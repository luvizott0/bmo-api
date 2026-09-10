<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPaymentResource extends JsonResource
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
            'subscription_member_id' => $this->subscription_member_id,
            'reference_month' => $this->reference_month,
            'amount' => (float) $this->amount,
            'status' => $this->status?->value ?? $this->status,
            'payment_date' => $this->payment_date?->format('Y-m-d') ?? $this->payment_date,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
