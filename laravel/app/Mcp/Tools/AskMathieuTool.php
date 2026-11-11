<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Services\Telegram;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('ask_mathieu')]
#[Description('Pose une question à Mathieu quand une décision lui appartient : choix de design, action irréversible, ambiguïté réelle. Après l\'appel, arrête-toi.')]
class AskMathieuTool extends Tool
{
    public function __construct(private Telegram $telegram) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'question' => 'required|string',
            'options' => 'nullable|array',
            'options.*' => 'string',
        ]);

        // getenv() et pas env() : TASK_ID vient du mcp-config de la tâche, et env() est vide avec la config en cache
        $task = Task::findOrFail(getenv('TASK_ID'));

        $question = $task->questions()->create([
            'kind' => 'input',
            'question' => $validated['question'],
            'options' => $validated['options'] ?? null,
            'asked_at' => now(),
        ]);

        // Envoi synchrone : passé par la queue, il attendrait derrière le run claude en cours
        $this->telegram->sendQuestion($question);

        return Response::text(
            'Question transmise à Mathieu. Il répondra plus tard, et sa réponse relancera cette session. '
            .'Arrête-toi maintenant : ne fais plus aucune action et termine ta réponse.'
        );
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('La question, compréhensible sans le reste de la conversation.')
                ->required(),
            'options' => $schema->array()
                ->items($schema->string())
                ->description('Deux à quatre réponses concrètes proposées à Mathieu.'),
        ];
    }
}
