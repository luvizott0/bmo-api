<?php

namespace App\Http\Resources;

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\TransactionStatus;
use Carbon\CarbonInterface;
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
        $currentMonth = $request->input('reference_month', now()->format('Y-m'));

        $isPaid = false;
        $currentPayment = null;

        if ($this->relationLoaded('members') && $this->members->isNotEmpty()) {
            $paidMembersCount = $this->members->filter(function ($member) use ($currentMonth) {
                if (! $member->relationLoaded('payments')) {
                    return false;
                }

                return $member->payments
                    ->where('reference_month', $currentMonth)
                    ->filter(fn ($p) => $p->status === SubscriptionPaymentStatus::Paid || $p->status === 'paid')
                    ->isNotEmpty();
            })->count();

            $isPaid = $paidMembersCount === $this->members->count();
        } elseif ($this->relationLoaded('transactions')) {
            $paidTx = $this->transactions
                ->filter(function ($t) use ($currentMonth) {
                    $dateStr = $t->occurred_at instanceof CarbonInterface
                        ? $t->occurred_at->format('Y-m')
                        : substr((string) $t->occurred_at, 0, 7);

                    return $dateStr === $currentMonth && ($t->status === TransactionStatus::Paid || $t->status === 'paid');
                })
                ->sortByDesc('occurred_at')
                ->first();

            $isPaid = $paidTx !== null;
            if ($paidTx) {
                $currentPayment = [
                    'id' => $paidTx->id,
                    'amount' => (float) $paidTx->amount,
                    'occurred_at' => $paidTx->occurred_at instanceof CarbonInterface ? $paidTx->occurred_at->toDateString() : (string) $paidTx->occurred_at,
                    'status' => $paidTx->status instanceof \BackedEnum ? $paidTx->status->value : (string) $paidTx->status,
                    'bank_account_id' => $paidTx->bank_account_id,
                    'credit_card_id' => $paidTx->credit_card_id,
                ];
            }
        }

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
            'is_paid' => $isPaid,
            'current_payment' => $currentPayment,
            'members' => SubscriptionMemberResource::collection($this->whenLoaded('members')),
            'credit_card' => new CreditCardResource($this->whenLoaded('creditCard')),
            'bank_account' => new BankAccountResource($this->whenLoaded('bankAccount')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
