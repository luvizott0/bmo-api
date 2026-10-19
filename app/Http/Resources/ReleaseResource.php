<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReleaseResource extends JsonResource
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
            'version' => $this->version,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'deployed_to_sandbox_at' => $this->deployed_to_sandbox_at?->toISOString(),
            'deployed_to_production_at' => $this->deployed_to_production_at?->toISOString(),
            'release_notes' => $this->release_notes,
            'logs' => $this->logs,
            'project' => new ProjectResource($this->whenLoaded('project')),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
