<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'project_id',
    'version',
    'title',
    'description',
    'status',
    'deployed_to_sandbox_at',
    'deployed_to_production_at',
    'release_notes',
    'logs',
])]
class Release extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'deployed_to_sandbox_at' => 'datetime',
            'deployed_to_production_at' => 'datetime',
            'logs' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
