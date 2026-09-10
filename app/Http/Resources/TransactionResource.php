<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'created_by_user_id' => $this->created_by_user_id,
            'type' => $this->type?->value ?? $this->type,
            'amount' => (float) $this->amount,
            'occurred_at' => $this->occurred_at?->format('Y-m-d') ?? $this->occurred_at,
            'status' => $this->status?->value ?? $this->status,
            'description' => $this->description,
            'bank_account_id' => $this->bank_account_id,
            'credit_card_id' => $this->credit_card_id,
            'category_id' => $this->category_id,
            'fixed_bill_id' => $this->fixed_bill_id,
            'subscription_id' => $this->subscription_id,
            'notes' => $this->notes,
            'installment_number' => $this->installment_number,
            'total_installments' => $this->total_installments,
            'installment_group_id' => $this->installment_group_id,
            'bank_account' => new BankAccountResource($this->whenLoaded('bankAccount')),
            'credit_card' => new CreditCardResource($this->whenLoaded('creditCard')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
