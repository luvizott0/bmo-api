<?php

namespace App\Http\Resources;

use App\Enums\TransactionStatus;
use Carbon\CarbonInterface;
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
        $currentMonth = $request->input('reference_month', now()->format('Y-m'));

        $currentPayment = null;
        if ($this->relationLoaded('transactions')) {
            $currentPayment = $this->transactions
                ->filter(function ($t) use ($currentMonth) {
                    $dateStr = $t->occurred_at instanceof CarbonInterface
                        ? $t->occurred_at->format('Y-m')
                        : substr((string) $t->occurred_at, 0, 7);

                    return $dateStr === $currentMonth && ($t->status === TransactionStatus::Paid || $t->status === 'paid');
                })
                ->sortByDesc('occurred_at')
                ->first();
        }

        $payments = [];
        if ($this->relationLoaded('transactions')) {
            $payments = $this->transactions
                ->filter(fn ($t) => $t->status === TransactionStatus::Paid || $t->status === 'paid')
                ->sortByDesc('occurred_at')
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'amount' => (float) $t->amount,
                    'occurred_at' => $t->occurred_at instanceof CarbonInterface ? $t->occurred_at->toDateString() : (string) $t->occurred_at,
                    'reference_month' => substr($t->occurred_at instanceof CarbonInterface ? $t->occurred_at->toDateString() : (string) $t->occurred_at, 0, 7),
                    'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status,
                    'bank_account_id' => $t->bank_account_id,
                    'credit_card_id' => $t->credit_card_id,
                ])
                ->values()
                ->all();
        }

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'color_hex' => $this->color_hex ?? '#3b82f6',
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
            'is_paid' => $currentPayment !== null,
            'current_payment' => $currentPayment ? [
                'id' => $currentPayment->id,
                'amount' => (float) $currentPayment->amount,
                'occurred_at' => $currentPayment->occurred_at instanceof CarbonInterface ? $currentPayment->occurred_at->toDateString() : (string) $currentPayment->occurred_at,
                'status' => $currentPayment->status instanceof \BackedEnum ? $currentPayment->status->value : (string) $currentPayment->status,
                'bank_account_id' => $currentPayment->bank_account_id,
                'credit_card_id' => $currentPayment->credit_card_id,
            ] : null,
            'payments' => $payments,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
