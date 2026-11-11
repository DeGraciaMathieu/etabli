<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Services\Telegram;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

// Appelé par Claude Code (--permission-prompt-tool) pour chaque outil hors des allowed_tools
#[Name('approve')]
#[Description('Décide si Claude peut utiliser un outil qui n\'est pas autorisé d\'office.')]
class ApproveTool extends Tool
{
    public function __construct(private Telegram $telegram) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $toolName = $request->get('tool_name');
        $input = $request->get('input', []);

        if ($this->isSafe($toolName, $input)) {
            return Response::text(json_encode(['behavior' => 'allow', 'updatedInput' => (object) $input]));
        }

        // getenv() et pas env() : TASK_ID vient du mcp-config de la tâche, et env() est vide avec la config en cache
        $task = Task::findOrFail(getenv('TASK_ID'));
        $rule = $this->rule($toolName, $input);

        // Claude peut retenter la même action : une seule demande à la fois
        if (! $task->questions()->pending()->where('kind', 'permission')->where('question', $rule)->exists()) {
            $question = $task->questions()->create([
                'kind' => 'permission',
                'question' => $rule,
                'options' => ['Autoriser', 'Refuser'],
                'asked_at' => now(),
            ]);

            $this->telegram->sendQuestion($question);
        }

        return Response::text(json_encode([
            'behavior' => 'deny',
            'message' => "Permission demandée à Mathieu pour {$rule}. Sa réponse relancera cette session. "
                .'Arrête-toi maintenant : ne fais plus aucune action et termine ta réponse.',
        ]));
    }

    // La règle exacte ajoutée aux allowed_tools de la tâche si Mathieu autorise
    private function rule(string $toolName, array $input): string
    {
        return $toolName === 'Bash' ? "Bash({$input['command']})" : $toolName;
    }

    private function isSafe(string $toolName, array $input): bool
    {
        if (in_array($toolName, config('etabli.auto_approve.tools'), true)) {
            return true;
        }

        if ($toolName !== 'Bash') {
            return false;
        }

        $command = trim($input['command'] ?? '');

        // Un opérateur shell peut enchaîner n'importe quoi derrière une commande sûre (ls && rm -rf …)
        if (preg_match('/[;&|`$<>\n\\\\]/', $command)) {
            return false;
        }

        // find sait aussi exécuter et supprimer
        if (Str::startsWith($command, 'find') && preg_match('/\s-(exec|execdir|ok|okdir|delete|fprint|fprintf|fls)\b/', $command)) {
            return false;
        }

        return collect(config('etabli.auto_approve.bash'))
            ->contains(fn (string $prefix) => $command === $prefix || Str::startsWith($command, "{$prefix} "));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_name' => $schema->string()->required(),
            'input' => $schema->object(),
            'tool_use_id' => $schema->string(),
        ];
    }
}
