<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'description' => $this->description,
            'github_repo' => $this->github_repo,
            'backend_repo' => $this->backend_repo,
            'backend_test_cmd' => $this->backend_test_cmd,
            'backend_instructions_file' => $this->backend_instructions_file,
            'frontend_repo' => $this->frontend_repo,
            'frontend_test_cmd' => $this->frontend_test_cmd,
            'frontend_instructions_file' => $this->frontend_instructions_file,
            'local_path' => $this->local_path,
            'base_branch' => $this->base_branch ?? 'develop',
            'test_command' => $this->test_command,
            'instructions_file' => $this->instructions_file ?? 'AGENTS.md',
            'color' => $this->color,
            'last_task_number' => (int) $this->last_task_number,
            'is_active' => (bool) $this->is_active,
            'tasks_count' => $this->whenCounted('tasks'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
