<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FixedBillResource extends JsonResource
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
            'type' => $this->type?->value ?? $this->type,
            'estimated_amount' => (float) $this->estimated_amount,
            'due_day' => $this->due_day,
            'category_id' => $this->category_id,
            'preferred_bank_account_id' => $this->preferred_bank_account_id,
            'is_active' => $this->is_active,
            'is_reminder_active' => $this->is_reminder_active,
            'reminder_days_before' => $this->reminder_days_before,
            'notes' => $this->notes,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'preferred_bank_account' => new BankAccountResource($this->whenLoaded('preferredBankAccount')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
