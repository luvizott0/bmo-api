<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionMemberResource extends JsonResource
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
            'subscription_id' => $this->subscription_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'contact' => $this->contact,
            'installment_amount' => (float) $this->installment_amount,
            'is_active' => $this->is_active,
            'payments' => SubscriptionPaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
