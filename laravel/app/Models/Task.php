<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Task extends Model
{
    protected $fillable = [
        'repo',
        'worktree_path',
        'prompt',
        'status',
        'allowed_tools',
    ];

    protected $attributes = [
        'status' => 'queued',
    ];

    protected static function booted(): void
    {
        // L'app choisit l'id de session : claude -p --session-id au lancement, --resume ensuite
        static::creating(function (Task $task) {
            $task->session_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'allowed_tools' => 'array',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }
}
