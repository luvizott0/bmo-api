<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
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
            'service_name' => $this->service_name,
            'color_hex' => $this->color_hex ?? '#6366f1',
            'total_amount' => (float) $this->total_amount,
            'billing_day' => $this->billing_day,
            'credit_card_id' => $this->credit_card_id,
            'bank_account_id' => $this->bank_account_id,
            'category_id' => $this->category_id,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'members' => SubscriptionMemberResource::collection($this->whenLoaded('members')),
            'credit_card' => new CreditCardResource($this->whenLoaded('creditCard')),
            'bank_account' => new BankAccountResource($this->whenLoaded('bankAccount')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
