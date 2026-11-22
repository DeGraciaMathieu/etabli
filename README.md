# Établi

Une EC2 qui fait tourner Claude Code en headless sur tes repos, pilotée depuis Telegram. Tu lances une tâche avec `/tache`, Claude travaille seul dans un worktree, et quand une décision t'appartient il te pose la question sur Telegram. Ta réponse relance la session là où elle s'était arrêtée.

![Architecture d'Établi](docs/architecture.svg)

La machine n'a aucun port entrant : le bot interroge Telegram en long polling, Claude Code et Remote Control ouvrent des connexions sortantes, et ton poste passe par un SSH tunnelé dans SSM.

## Les flux

### Lancer une tâche

`/tache <projet> <consigne>` envoyé au bot :

1. `telegram-poll` reçoit le message. Seul l'id Telegram de `TELEGRAM_USER_ID` est écouté.
2. Le projet est cherché dans `~/code`. S'il n'y est pas, il est cloné avec `gh repo clone` (nom seul ou `owner/nom`). S'il n'existe pas non plus sur GitHub, le bot propose de créer un projet local vide, avec deux boutons, pour qu'une faute de frappe ne crée rien.
3. La tâche est enregistrée dans SQLite et un job `RunClaudeTask` part sur la queue `claude`.

### Un run

`queue-claude` dépile les runs un par un, sans jamais rejouer un run qui a échoué :

1. Un worktree `~/code/worktrees/task-N`, sur la branche `etabli/task-N`, est créé s'il n'existe pas. Il est gardé tant que la session peut reprendre.
2. `claude -p` démarre dans ce worktree avec `--session-id`, les `allowedTools` de la tâche, le serveur MCP `etabli` seul (`--strict-mcp-config`) et le prompt `laravel/prompts/headless.md`.
3. Le stream JSON est écrit dans `storage/logs/runs/<run>.jsonl`. Son dernier événement donne le coût et le nombre de tours du run.
4. Le statut final se décide ainsi : une question restée sans réponse l'emporte, sinon la tâche est `done` ou `failed`.

### Une question : `ask_mathieu`

Quand une décision t'appartient (choix de design, action irréversible, vraie ambiguïté), Claude appelle l'outil MCP `ask_mathieu` avec la question et deux à quatre options :

1. Le serveur MCP, lancé par `claude -p` en stdio, enregistre la question et l'envoie aussitôt sur Telegram. L'envoi est synchrone : passé par la queue, il attendrait derrière le run en cours.
2. Claude reçoit la consigne de s'arrêter, le run se termine et la tâche passe en `waiting_input`.
3. Tu réponds avec un bouton, en répondant au message de la question, ou en texte libre si une seule question attend.
4. `ResumeClaudeTask` relance la session avec `--resume`, dans le même worktree, avec ta réponse comme prompt.

### Une permission : `approve`

Un outil hors des `allowedTools` passe par `--permission-prompt-tool mcp__etabli__approve` :

- La lecture seule est autorisée d'office : `Glob`, `Grep`, et `ls`, `cat`, `grep`… sans opérateur shell (`find` seulement sans `-exec` ni `-delete`).
- Le reste devient une question **Autoriser / Refuser** sur Telegram. L'outil est refusé pour ce run, Claude s'arrête, et la tâche passe en `waiting_permission`.
- **Autoriser** ajoute la règle exacte (par exemple `Bash(make test)`) aux `allowedTools` de la tâche, puis la relance. Tout autre texte vaut refus, et ce texte est transmis à Claude comme précision.

### La fin d'une tâche

Le résultat part sur Telegram, que la tâche soit `done` ou `failed`. Répondre à ce message relance la session avec ta réponse : c'est comme ça qu'on demande une correction ou la suite.

```
queued → running → done | failed
            ↓
  waiting_input | waiting_permission → (ta réponse) → queued → running …
```

### Remote Control

À côté des tâches, `claude remote-control` tourne dans tmux (service `claude-rc`) pour ouvrir une session interactive sur la machine depuis l'app Claude. Elle est indépendante des tâches et de Telegram.

### Allumer, éteindre, déployer

Tout passe par Ansible depuis ton poste : `provision.yml` crée l'infra AWS, `site.yml` configure la machine et déploie l'app, `power.yml` allume ou éteint l'instance. Chaque soir à 23:00, `etabli-night-stop` éteint la machine, en attendant d'abord qu'aucune tâche ne soit `queued` ou `running`.

## Le repo

- `ansible/` : l'infra et la configuration de l'EC2. L'installation pas à pas est dans [ansible/README.md](ansible/README.md).
- `laravel/` : l'app. Le bot (`TelegramPoll`, `TelegramUpdateHandler`), les jobs (`RunClaudeTask`, `ResumeClaudeTask`) et le serveur MCP (`app/Mcp`).
