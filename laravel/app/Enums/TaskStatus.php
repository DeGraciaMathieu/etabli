<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case WaitingInput = 'waiting_input';
    case Done = 'done';
    case Failed = 'failed';
}
