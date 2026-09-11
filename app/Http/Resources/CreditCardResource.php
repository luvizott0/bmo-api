<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditCardResource extends JsonResource
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
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->bankAccount ? [
                'id' => $this->bankAccount->id,
                'name' => $this->bankAccount->name,
                'color_hex' => $this->bankAccount->color_hex,
            ] : null,
            'name' => $this->name,
            'type' => $this->type ?? 'credit',
            'total_limit' => (float) $this->total_limit,
            'daily_limit' => $this->daily_limit !== null ? (float) $this->daily_limit : null,
            'available_limit' => $this->available_limit,
            'closing_day' => $this->closing_day,
            'due_day' => $this->due_day,
            'brand' => $this->brand,
            'color_hex' => $this->color_hex,
            'is_active' => $this->is_active,
            'user_id' => $this->user_id,
            'is_shared' => $this->is_shared !== null ? (bool) $this->is_shared : true,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
