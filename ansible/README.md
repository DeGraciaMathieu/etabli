# etabli-ansible

Configure l'EC2 d'Établi : Claude Code (headless + Remote Control), gh, PHP, l'app Laravel et ses process supervisor.

Deux playbooks :
- `provision.yml` crée l'infra AWS depuis ton poste : key pair, rôle IAM (SSM seulement), security group sans entrant, instance Ubuntu 24.04 ARM. Il écrit `inventory.ini`.
- `site.yml` configure la machine.

## Prérequis sur ton poste

- `uv`, la CLI `aws` (profil `etabli` configuré), le plugin `session-manager-plugin`
- Ansible, boto3 et botocore sont gérés par uv (`pyproject.toml` + `uv.lock`), dans `.venv/` :

```bash
uv sync
uv run ansible-galaxy collection install -r requirements.yml   # dans ./collections
```

Toutes les commandes `ansible*` ci-dessous se lancent préfixées par `uv run`.

## Quel compte AWS ?

Celui du profil `aws_profile` (par défaut `etabli`), en haut de `provision.yml`. Le même profil sert aux modules Ansible, à l'attente SSM et au tunnel SSH de l'inventaire. Le playbook vérifie d'abord que ce profil pointe bien vers `aws_account_id`, et s'arrête sinon.

```bash
aws configure --profile etabli            # ou : aws configure sso --profile etabli
aws sts get-caller-identity --profile etabli   # l'Account affiché va dans aws_account_id
```

## Créer l'utilisateur IAM du profil

Avec des identifiants admin (une seule fois). La policy `iam/etabli-operator-policy.json` donne juste ce qu'il faut à `provision.yml` et aux sessions SSM : EC2 limité à `eu-north-1`, IAM limité aux rôles `etabli-*` (auxquels seule `AmazonSSMManagedInstanceCore` peut être attachée), sessions SSM limitées aux instances taguées `Project=etabli`.

```bash
ACCOUNT=$(aws sts get-caller-identity --query Account --output text)

aws iam create-policy --policy-name etabli-operator \
  --policy-document file://iam/etabli-operator-policy.json
aws iam create-user --user-name etabli
aws iam attach-user-policy --user-name etabli \
  --policy-arn arn:aws:iam::$ACCOUNT:policy/etabli-operator
aws iam create-access-key --user-name etabli    # → AccessKeyId + SecretAccessKey

aws configure --profile etabli                  # colle les clés, région eu-north-1
```

Si tu changes de région, change aussi `aws:RequestedRegion` dans la policy.

## Premier lancement

```bash
# 1. La clé SSH (sert uniquement au SSH tunnelé dans SSM)
ssh-keygen -t ed25519 -f ~/.ssh/etabli -C "etabli-ec2"

# 2. L'infra AWS (région, type d'instance et disque en haut du fichier)
uv run ansible-playbook provision.yml
uv run ansible etabli -m ping

# 3. Les secrets
cp group_vars/all/vault.yml.example group_vars/all/vault.yml
# remplir les valeurs, puis :
uv run ansible-vault encrypt group_vars/all/vault.yml

# 4. La machine, sans Établi tant que l'app n'existe pas
uv run ansible-playbook site.yml --tags base,gh,php,claude --ask-vault-pass
```

`provision.yml` est idempotent : le relancer ne recrée rien. Pour changer de type d'instance, arrête-la, modifie `etabli_instance_type`, relance.

## La seule étape manuelle : connecter Claude Code

La connexion OAuth à ton compte claude.ai ne s'automatise pas (et Remote Control refuse les clés API comme les tokens `setup-token`).

```bash
aws ssm start-session --target i-0123456789abcdef0
sudo -iu ubuntu
cd ~/code && claude     # /login, ouvrir l'URL, coller le code, accepter la confiance du dossier, quitter
exit
```

Puis :

```bash
uv run ansible-playbook site.yml --tags claude --ask-vault-pass   # démarre Remote Control
```

Le playbook considère Claude connecté si `~/.claude/.credentials.json` existe. Vérifie ce chemin sur ta version de Claude Code.

## Ensuite

| Besoin | Commande |
|---|---|
| Déployer une nouvelle version d'Établi | `uv run ansible-playbook site.yml --tags etabli` |
| Changer les permissions de Claude | modifier `claude_allow` / `claude_deny`, puis `--tags claude` |
| Tout réappliquer | `uv run ansible-playbook site.yml` |
| Voir la session Remote Control | en SSM : `sudo -iu ubuntu tmux attach -t claude` |

Un déploiement n'interrompt jamais un run en cours : le handler utilise `php artisan queue:restart`, qui laisse chaque worker finir son job.

## À régler côté app

- SQLite avec trois process qui écrivent : passe `journal_mode` à `wal` et mets un `busy_timeout` dans `config/database.php`.
- `retry_after` de la connexion `database` dans `config/queue.php` doit lire `DB_QUEUE_RETRY_AFTER` (c'est le cas par défaut depuis Laravel 11).
