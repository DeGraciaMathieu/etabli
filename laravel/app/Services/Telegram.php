<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
