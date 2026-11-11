<?php

namespace App\Console\Commands;

use App\Services\Telegram;
use App\Services\TelegramUpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramPoll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:poll';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interroge Telegram en long polling et traite les réponses de Mathieu';

    /**
     * Execute the console command.
     */
    public function handle(Telegram $telegram, TelegramUpdateHandler $handler): void
    {
        $offset = Cache::get('telegram.offset', 0);

        while (true) {
            try {
                $updates = $telegram->getUpdates($offset);
            } catch (Throwable $e) {
                Log::warning('getUpdates en échec', ['error' => $e->getMessage()]);
                sleep(5);

                continue;
            }

            foreach ($updates as $update) {
                // Offset enregistré avant le traitement : un crash perd au pire un message, mais ne relance jamais une tâche deux fois
                $offset = $update['update_id'] + 1;
                Cache::forever('telegram.offset', $offset);

                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    Log::error('Update Telegram non traitée', ['update' => $update, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
