<?php

return [

    'claude_bin' => env('CLAUDE_BIN', 'claude'),

    // Repos des tâches et leurs worktrees
    'code_dir' => env('ETABLI_CODE_DIR', storage_path('code')),

    // Durée max d'un run claude -p, en secondes
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

];
