<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Run;
use App\Models\Task;
use App\Services\Telegram;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class RunClaudeTask implements ShouldQueue
{
    use Queueable;

    // Un run qui échoue n'est jamais rejoué tout seul
    public int $tries = 1;

    public int $timeout;

    public function __construct(public Task $task)
    {
        $this->onQueue('claude');

        // Le timeout du process claude tombe avant celui du worker
        $this->timeout = config('etabli.claude_timeout') + 60;
    }

    public function handle(): void
    {
        $this->task->update(['status' => TaskStatus::Running]);

        $worktree = $this->prepareWorktree();
        $mcpConfig = $this->writeMcpConfig();

        $run = $this->task->runs()->create([
            'kind' => $this->kind(),
            'started_at' => now(),
        ]);

        $logPath = storage_path("logs/runs/{$run->id}.jsonl");
        File::ensureDirectoryExists(dirname($logPath));

        $exitCode = $this->runClaude($worktree, $mcpConfig, $logPath);
        $result = $this->readResult($logPath);

        $run->update([
            'exit_code' => $exitCode,
            'cost' => $result['total_cost_usd'] ?? null,
            'turns' => $result['num_turns'] ?? null,
            'log_path' => $logPath,
            'ended_at' => now(),
        ]);

        $status = $this->finalStatus($exitCode, $result);

        $this->task->update(['status' => $status]);

        if (! in_array($status, [TaskStatus::WaitingInput, TaskStatus::WaitingPermission], true)) {
            $this->notifyResult($run, $status, $exitCode, $result);
        }
    }

    protected function kind(): string
    {
        return 'start';
    }

    protected function prompt(): string
    {
        return $this->task->prompt;
    }

    protected function sessionArguments(): array
    {
        return ['--session-id', $this->task->session_id];
    }

    // Une question sans réponse l'emporte : la tâche attend Mathieu, même si le run s'est mal fini
    private function finalStatus(?int $exitCode, array $result): TaskStatus
    {
        $pending = $this->task->questions()->pending()->latest('id')->first();

        if ($pending) {
            return $pending->kind === 'permission' ? TaskStatus::WaitingPermission : TaskStatus::WaitingInput;
        }

        $succeeded = $exitCode === 0 && ($result['subtype'] ?? null) === 'success';

        return $succeeded ? TaskStatus::Done : TaskStatus::Failed;
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->update(['status' => TaskStatus::Failed]);

        $this->notify("Tâche #{$this->task->id} en échec : ".($exception?->getMessage() ?? 'erreur inconnue'));
    }

    // Le résultat final part aussi sur Telegram : y répondre relance la tâche
    private function notifyResult(Run $run, TaskStatus $status, ?int $exitCode, array $result): void
    {
        $header = $status === TaskStatus::Done
            ? "Tâche #{$this->task->id} terminée."
            : "Tâche #{$this->task->id} en échec (".($result['subtype'] ?? ($exitCode === null ? 'timeout' : "code {$exitCode}")).').';

        $messageId = $this->notify($header."\n\n".Str::limit($result['result'] ?? '', 3500)."\n\nRéponds à ce message pour relancer la tâche.");

        $run->update(['telegram_message_id' => $messageId]);
    }

    // Un échec d'envoi ne doit pas faire échouer la tâche
    private function notify(string $text): ?int
    {
        try {
            return app(Telegram::class)->sendMessage($text);
        } catch (Throwable $e) {
            Log::error("Message Telegram non envoyé (task {$this->task->id})", ['error' => $e->getMessage()]);

            return null;
        }
    }

    // Un worktree et une branche par tâche, gardés tant que la session peut reprendre
    private function prepareWorktree(): string
    {
        $path = config('etabli.code_dir')."/worktrees/task-{$this->task->id}";

        if (! File::isDirectory($path)) {
            File::ensureDirectoryExists(dirname($path));

            Process::path($this->task->repo)
                ->run(['git', 'worktree', 'add', '-b', "etabli/task-{$this->task->id}", $path])
                ->throw();
        }

        $this->task->update(['worktree_path' => $path]);

        return $path;
    }

    private function writeMcpConfig(): string
    {
        $path = storage_path("app/mcp/task-{$this->task->id}.json");
        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'mcpServers' => [
                'etabli' => [
                    'command' => PHP_BINARY,
                    'args' => [base_path('artisan'), 'mcp:start', 'etabli'],
                    'env' => ['TASK_ID' => (string) $this->task->id],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function runClaude(string $worktree, string $mcpConfig, string $logPath): ?int
    {
        $log = fopen($logPath, 'w');

        try {
            return Process::path($worktree)
                ->timeout(config('etabli.claude_timeout'))
                ->run($this->command($mcpConfig), function (string $type, string $output) use ($log) {
                    if ($type === 'out') {
                        fwrite($log, $output);
                    } else {
                        Log::warning("claude stderr (task {$this->task->id})", ['output' => $output]);
                    }
                })
                ->exitCode();
        } catch (ProcessTimedOutException) {
            return null;
        } finally {
            fclose($log);
        }
    }

    private function command(string $mcpConfig): array
    {
        return [
            config('etabli.claude_bin'),
            '-p', $this->prompt(),
            ...$this->sessionArguments(),
            '--output-format', 'stream-json',
            '--verbose',
            // Seul le serveur etabli : sans ça, les connecteurs claude.ai du compte sont chargés
            '--mcp-config', $mcpConfig,
            '--strict-mcp-config',
            '--allowedTools', implode(',', $this->task->allowed_tools ?? config('etabli.allowed_tools')),
            // Tout outil hors des allowed_tools passe par approve : autorisé d'office ou demandé à Mathieu
            '--permission-prompt-tool', 'mcp__etabli__approve',
            '--append-system-prompt', File::get(base_path('prompts/headless.md')),
            '--max-turns', (string) config('etabli.max_turns'),
        ];
    }

    // Le dernier événement du stream-json, de type « result », porte le bilan du run
    private function readResult(string $logPath): array
    {
        foreach (array_reverse(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
            $event = json_decode($line, true);

            if (($event['type'] ?? null) === 'result') {
                return $event;
            }
        }

        return [];
    }
}
