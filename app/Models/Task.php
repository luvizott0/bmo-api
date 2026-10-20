<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'project_id',
    'release_id',
    'task_code',
    'task_number',
    'title',
    'description',
    'type',
    'status',
    'priority',
    'target_scope',
    'branch_name',
    'backend_branch_name',
    'frontend_branch_name',
    'assignee_type',
    'ai_logs',
    'order',
    'due_date',
    'completed_at',
])]
class Task extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'task_number' => 'integer',
            'order' => 'integer',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'ai_logs' => 'array',
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

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }
}
