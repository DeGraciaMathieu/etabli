<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    protected $fillable = [
        'kind',
        'question',
        'options',
        'answer',
        'telegram_message_id',
        'asked_at',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('answered_at');
    }
}
