<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AskMathieuTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('etabli')]
#[Version('0.1.0')]
#[Instructions('Outils d\'Établi pour une tâche Claude Code lancée sans humain présent.')]
class EtabliServer extends Server
{
    protected array $tools = [
        AskMathieuTool::class,
    ];
}
