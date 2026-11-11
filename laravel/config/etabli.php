<?php

return [

    'claude_bin' => env('CLAUDE_BIN', 'claude'),

    // Repos des tâches et leurs worktrees
    'code_dir' => env('ETABLI_CODE_DIR', storage_path('code')),

    // Durée max d'un run claude -p, en secondes
    // Propriétaire GitHub par défaut quand /tache reçoit un nom de repo sans owner
    'github_owner' => env('ETABLI_GITHUB_OWNER', 'DeGraciaMathieu'),

    'claude_timeout' => (int) env('ETABLI_CLAUDE_TIMEOUT', 3600),

    'max_turns' => (int) env('ETABLI_MAX_TURNS', 60),

    // Outils autorisés quand la tâche n'en précise pas
    'allowed_tools' => [
        'mcp__etabli__ask_mathieu',
        'Read',
        'Edit',
        'Write',
        'Bash(git *)',
        'Bash(composer *)',
        'Bash(npm *)',
    ],

    // Autorisés d'office par l'outil approve, sans demander à Mathieu : lecture seule
    'auto_approve' => [
        'tools' => ['Glob', 'Grep'],
        // Début de commande Bash : la commande elle-même, ou suivie d'arguments
        'bash' => ['ls', 'cat', 'head', 'tail', 'wc', 'find', 'grep', 'rg', 'pwd', 'php -l'],
    ],

];
