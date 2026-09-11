<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
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
            'bank_name' => $this->bank_name,
            'name' => $this->name,
            'type' => $this->type?->value ?? $this->type,
            'current_balance' => (float) $this->current_balance,
            'color_hex' => $this->color_hex,
            'is_active' => $this->is_active,
            'is_primary' => (bool) $this->is_primary,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
