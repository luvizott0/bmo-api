<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'project_id' => $this->project_id,
            'release_id' => $this->release_id,
            'task_code' => $this->task_code,
            'task_number' => (int) $this->task_number,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type ?? 'feature',
            'status' => $this->status,
            'priority' => $this->priority,
            'target_scope' => $this->target_scope ?? 'auto',
            'branch_name' => $this->branch_name,
            'backend_branch_name' => $this->backend_branch_name,
            'frontend_branch_name' => $this->frontend_branch_name,
            'assignee_type' => $this->assignee_type,
            'preview_url' => $this->ai_logs['preview_url'] ?? ('http://' . strtolower($this->task_code) . '.preview.bmo'),
            'ai_logs' => $this->ai_logs,
            'order' => (int) $this->order,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'completed_at' => $this->completed_at?->toISOString(),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
