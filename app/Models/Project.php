<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'name',
    'prefix',
    'description',
    'github_repo',
    'local_path',
    'base_branch',
    'test_command',
    'instructions_file',
    'color',
    'last_task_number',
    'is_active',
])]
class Project extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_task_number' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * Generate the next atomic sequential task code (e.g. CST-001).
     *
     * @return array{number: int, code: string}
     */
    public function allocateNextTaskCode(): array
    {
        $this->increment('last_task_number');
        $this->refresh();

        $number = (int) $this->last_task_number;
        $prefix = strtoupper(trim($this->prefix));
        $code = sprintf('%s-%03d', $prefix, $number);

        return [
            'number' => $number,
            'code' => $code,
        ];
    }
}
