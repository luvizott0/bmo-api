<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'start_at' => $this->start_at?->toISOString(),
            'end_at' => $this->end_at?->toISOString(),
            'all_day' => (bool) $this->all_day,
            'category' => $this->category,
            'color' => $this->color,
            'location' => $this->location,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
