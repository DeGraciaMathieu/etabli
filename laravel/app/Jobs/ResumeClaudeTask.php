<?php

namespace App\Jobs;

use App\Models\Task;

// Relance la session de la tâche, dans le même worktree, avec la réponse de Mathieu comme prompt
class ResumeClaudeTask extends RunClaudeTask
{
    public function __construct(Task $task, public string $message)
    {
        parent::__construct($task);
    }

    protected function kind(): string
    {
        return 'resume';
    }

    protected function prompt(): string
    {
        return $this->message;
    }

    protected function sessionArguments(): array
    {
        return ['--resume', $this->task->session_id];
    }
}
