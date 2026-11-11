<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Telegram
{
    public function sendMessage(string $text, ?array $replyMarkup = null): int
    {
        $response = $this->call('sendMessage', array_filter([
            'chat_id' => config('services.telegram.user_id'),
            // Un message Telegram fait au plus 4096 caractères
            'text' => Str::limit($text, 4000),
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
        ]));

        return $response['message_id'];
    }

    // Boutons inline pour les options, sinon force_reply pour une réponse libre
    public function sendQuestion(Question $question): void
    {
        $text = $question->kind === 'permission'
            ? "Tâche #{$question->task_id} veut utiliser : {$question->question}"
            : "Tâche #{$question->task_id} : {$question->question}";

        $markup = $question->options
            ? ['inline_keyboard' => collect($question->options)
                ->map(fn (string $option, int $i) => [['text' => $option, 'callback_data' => "q:{$question->id}:{$i}"]])
                ->all()]
            : ['force_reply' => true];

        try {
            $question->update(['telegram_message_id' => $this->sendMessage($text, $markup)]);
        } catch (Throwable $e) {
            // La question reste en base : la tâche passera quand même en attente
            Log::error("Question {$question->id} non envoyée sur Telegram", ['error' => $e->getMessage()]);
        }
    }

    // Long polling : Telegram garde la requête ouverte jusqu'à $timeout secondes s'il n'a rien
    public function getUpdates(int $offset, int $timeout = 30): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ], $timeout + 10);
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackQueryId]);
    }

    public function removeButtons(int $messageId): void
    {
        $this->call('editMessageReplyMarkup', [
            'chat_id' => config('services.telegram.user_id'),
            'message_id' => $messageId,
        ]);
    }

    private function call(string $method, array $params, int $timeout = 15): mixed
    {
        return Http::timeout($timeout)
            ->asForm()
            ->post('https://api.telegram.org/bot'.config('services.telegram.token')."/{$method}", $params)
            ->throw()
            ->json('result');
    }
}
