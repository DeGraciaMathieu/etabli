<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\ResumeClaudeTask;
use App\Models\Question;
use App\Models\Run;
use App\Models\Task;

class TelegramUpdateHandler
{
    public function __construct(private Telegram $telegram) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleButton($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    // Un bouton d'option : callback_data = "q:<question>:<index de l'option>"
    private function handleButton(array $callback): void
    {
        if (! $this->fromMathieu($callback['from'])) {
            return;
        }

        $this->telegram->answerCallbackQuery($callback['id']);

        if (! preg_match('/^q:(\d+):(\d+)$/', $callback['data'] ?? '', $matches)) {
            return;
        }

        $question = Question::find($matches[1]);
        $option = $question?->options[$matches[2]] ?? null;

        if ($option === null || $question->answered_at) {
            $this->telegram->sendMessage('Cette question a déjà une réponse.');

            return;
        }

        $this->telegram->removeButtons($callback['message']['message_id']);
        $this->answer($question, $option);
    }

    private function handleMessage(array $message): void
    {
        if (! $this->fromMathieu($message['from']) || ! isset($message['text'])) {
            return;
        }

        $text = $message['text'];

        if ($replyTo = $message['reply_to_message']['message_id'] ?? null) {
            $this->handleReply((string) $replyTo, $text);

            return;
        }

        $pending = Question::pending()->get();

        match ($pending->count()) {
            0 => $this->telegram->sendMessage("Aucune question en attente. Pour relancer une tâche, réponds au message de son résultat."),
            1 => $this->answer($pending->first(), $text),
            default => $this->telegram->sendMessage(
                'Plusieurs questions attendent (tâches #'.$pending->pluck('task_id')->unique()->implode(', #')
                .'). Réponds directement au message de la question.'
            ),
        };
    }

    // Une réponse à un message du bot : celui d'une question, ou celui d'un résultat final
    private function handleReply(string $messageId, string $text): void
    {
        if ($question = Question::firstWhere('telegram_message_id', $messageId)) {
            $question->answered_at
                ? $this->telegram->sendMessage('Cette question a déjà une réponse.')
                : $this->answer($question, $text);

            return;
        }

        if ($run = Run::firstWhere('telegram_message_id', $messageId)) {
            $this->resume($run->task, "Message de Mathieu : {$text}");

            return;
        }

        $this->telegram->sendMessage('Je ne retrouve ni question ni tâche liée à ce message.');
    }

    private function answer(Question $question, string $answer): void
    {
        $question->update(['answer' => $answer, 'answered_at' => now()]);

        $this->resume($question->task, "Réponse de Mathieu à « {$question->question} » : {$answer}");
    }

    private function resume(Task $task, string $message): void
    {
        $task->update(['status' => TaskStatus::Queued]);

        ResumeClaudeTask::dispatch($task, $message);

        $this->telegram->sendMessage("Reçu, je relance la tâche #{$task->id}.");
    }

    // Seul Mathieu pilote le bot
    private function fromMathieu(array $from): bool
    {
        return (string) $from['id'] === (string) config('services.telegram.user_id');
    }
}
