<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\ResumeClaudeTask;
use App\Jobs\RunClaudeTask;
use App\Models\Question;
use App\Models\Run;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TelegramUpdateHandler
{
    public function __construct(private Telegram $telegram, private Projects $projects) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleButton($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    // Boutons : "q:<question>:<index de l'option>" ou "np:<create|cancel>:<clé de la demande>"
    private function handleButton(array $callback): void
    {
        if (! $this->fromMathieu($callback['from'])) {
            return;
        }

        $this->telegram->answerCallbackQuery($callback['id']);

        if (preg_match('/^np:(create|cancel):(\w+)$/', $callback['data'] ?? '', $matches)) {
            $this->handleNewProjectButton($matches[1], $matches[2], $callback['message']['message_id']);

            return;
        }

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

        if (preg_match('#^/tache(@\w+)?(\s|$)#', $text)) {
            $this->handleNewTask($text);

            return;
        }

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

    // /tache <projet> <consigne> : projet déjà cloné, sinon cloné depuis GitHub, sinon créé après confirmation
    private function handleNewTask(string $text): void
    {
        if (! preg_match('#^/tache(?:@\w+)?\s+(\S+)\s+(.+)$#s', $text, $matches)) {
            $this->telegram->sendMessage("Usage : /tache <projet> <consigne>\nLe projet est un dossier de ~/code, un repo GitHub (nom ou owner/nom), ou un nouveau projet.");

            return;
        }

        [, $name, $prompt] = $matches;

        if (! $this->projects->isValidName($name)) {
            $this->telegram->sendMessage("Nom de projet invalide : {$name}. Lettres, chiffres, . _ - et un owner/ facultatif.");

            return;
        }

        if ($this->projects->isCloned($name) || $this->projects->cloneFromGitHub($name)) {
            $this->startTask($name, trim($prompt));

            return;
        }

        // La consigne attend la confirmation : une faute de frappe ne doit pas créer de projet
        $key = Str::random(12);
        Cache::put("telegram.new_project.{$key}", ['name' => $name, 'prompt' => trim($prompt)], now()->addDay());

        $this->telegram->sendMessage(
            "Le projet {$name} n'est ni sur l'EC2 ni sur GitHub (ou ton token n'y a pas accès). Créer un nouveau projet local ?",
            ['inline_keyboard' => [
                [['text' => "Créer le projet {$name}", 'callback_data' => "np:create:{$key}"]],
                [['text' => 'Annuler', 'callback_data' => "np:cancel:{$key}"]],
            ]],
        );
    }

    private function handleNewProjectButton(string $action, string $key, int $messageId): void
    {
        $pending = Cache::pull("telegram.new_project.{$key}");

        if (! $pending) {
            $this->telegram->sendMessage('Cette demande a expiré ou a déjà été traitée.');

            return;
        }

        $this->telegram->removeButtons($messageId);

        if ($action === 'cancel') {
            $this->telegram->sendMessage('Annulé, aucun projet créé.');

            return;
        }

        if (! $this->projects->isCloned($pending['name'])) {
            $this->projects->create($pending['name']);
        }

        $this->startTask($pending['name'], $pending['prompt']);
    }

    private function startTask(string $name, string $prompt): void
    {
        $task = Task::create(['repo' => $this->projects->path($name), 'prompt' => $prompt]);

        RunClaudeTask::dispatch($task);

        $this->telegram->sendMessage("Tâche #{$task->id} créée sur {$name}, je la lance.");
    }

    private function answer(Question $question, string $answer): void
    {
        $question->update(['answer' => $answer, 'answered_at' => now()]);

        if ($question->kind === 'permission') {
            $this->answerPermission($question, $answer);

            return;
        }

        $this->resume($question->task, "Réponse de Mathieu à « {$question->question} » : {$answer}");
    }

    // Autoriser ajoute la règle exacte aux allowed_tools de la tâche ; tout autre texte vaut refus
    private function answerPermission(Question $question, string $answer): void
    {
        $task = $question->task;

        if ($answer === 'Autoriser') {
            $task->update(['allowed_tools' => collect($task->allowed_tools ?? config('etabli.allowed_tools'))
                ->push($question->question)
                ->unique()
                ->values()
                ->all()]);

            $this->resume($task, "Mathieu autorise {$question->question}. Tu peux réessayer.");

            return;
        }

        $reason = $answer === 'Refuser' ? '' : " Il précise : {$answer}.";

        $this->resume($task, "Mathieu refuse {$question->question}.{$reason} Continue sans, ou arrête-toi si c'est impossible.");
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
