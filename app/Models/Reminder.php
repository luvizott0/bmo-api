<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'title',
    'due_at',
    'is_completed',
    'completed_at',
    'category',
    'recurrence',
    'notify_whatsapp',
    'last_notified_at',
])]
class Reminder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'notify_whatsapp' => 'boolean',
            'last_notified_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
