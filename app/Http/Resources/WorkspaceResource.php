<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceResource extends JsonResource
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
            'name' => $this->name,
            'is_personal' => $this->is_personal,
            'owner_id' => $this->owner_id,
            'role' => $this->pivot?->role ?? ($this->owner_id === $request->user()?->id ? 'owner' : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
