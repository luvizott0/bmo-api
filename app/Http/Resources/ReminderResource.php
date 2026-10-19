<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReminderResource extends JsonResource
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
            'due_at' => $this->due_at?->toISOString(),
            'is_completed' => (bool) $this->is_completed,
            'completed_at' => $this->completed_at?->toISOString(),
            'category' => $this->category,
            'recurrence' => $this->recurrence,
            'notify_whatsapp' => (bool) $this->notify_whatsapp,
            'last_notified_at' => $this->last_notified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
