<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCategoryResource extends JsonResource
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
            'color_hex' => $this->color_hex ?? '#6366F1',
            'icon' => $this->icon,
            'is_active' => (bool) $this->is_active,
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
