<?php

namespace App\Services;

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Subscription;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use App\Models\Transaction;
use App\Models\Workspace;
use Carbon\Carbon;

class SubscriptionService
{
    public function __construct(
        private readonly TransactionService $transactionService
    ) {}

    /**
     * Process due subscriptions for a workspace.
     * On or after the billing day of the current month, active subscriptions
     * linked to a credit card automatically create a pending expense transaction
     * to discount the card's available limit.
     */
    public function processDueSubscriptions(Workspace $workspace): int
    {
        $today = now();
        $currentMonth = $today->format('Y-m');
        $processedCount = 0;

        $subscriptions = $workspace->subscriptions()
            ->where('is_active', true)
            ->whereNotNull('credit_card_id')
            ->get();

        foreach ($subscriptions as $subscription) {
            $daysInMonth = (int) $today->daysInMonth;
            $billingDay = min((int) $subscription->billing_day, $daysInMonth);

            // Check if billing day has arrived in the current month
            if ((int) $today->day >= $billingDay) {
                // Check if a transaction for this subscription and credit card already exists for this cycle
                $alreadyExists = Transaction::where('subscription_id', $subscription->id)
                    ->where('credit_card_id', $subscription->credit_card_id)
                    ->where('occurred_at', 'like', "{$currentMonth}%")
                    ->exists();

                if (! $alreadyExists) {
                    $dueDate = Carbon::createFromDate((int) $today->year, (int) $today->month, $billingDay)->format('Y-m-d');

                    Transaction::create([
                        'workspace_id' => $workspace->id,
                        'created_by_user_id' => $workspace->owner_id,
                        'type' => TransactionType::Expense,
                        'amount' => $subscription->total_amount,
                        'occurred_at' => $dueDate,
                        'status' => TransactionStatus::Pending,
                        'description' => 'Assinatura: '.$subscription->service_name,
                        'credit_card_id' => $subscription->credit_card_id,
                        'category_id' => $subscription->category_id,
                        'subscription_id' => $subscription->id,
                        'notes' => "Cobrança de ciclo {$currentMonth}",
                    ]);

                    $processedCount++;
                }
            }
        }

        return $processedCount;
    }

    /**
     * Record or update payment status for a member in a specific billing cycle.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordMemberPayment(
        SubscriptionMember $member,
        string $referenceMonth,
        string $status,
        float $amount,
        ?string $paymentDate = null,
        ?string $pixE2EId = null,
        ?array $metadata = null,
        ?int $userId = null
    ): SubscriptionPayment {
        $subscription = $member->subscription;
        $paymentDate = $paymentDate ?? ($status === 'paid' ? now()->toDateString() : null);

        $previousPayment = SubscriptionPayment::where('subscription_member_id', $member->id)
            ->where('reference_month', $referenceMonth)
            ->first();

        $wasPaid = $previousPayment && ($previousPayment->status === SubscriptionPaymentStatus::Paid || $previousPayment->status?->value === 'paid' || $previousPayment->status === 'paid');
        $isNowPaid = $status === SubscriptionPaymentStatus::Paid->value || $status === 'paid';

        $dataToUpdate = [
            'amount' => $amount,
            'status' => $status,
            'payment_date' => $paymentDate,
        ];

        if ($pixE2EId) {
            $dataToUpdate['pix_e2e_id'] = $pixE2EId;
        }

        if ($metadata) {
            $dataToUpdate['receipt_metadata'] = $metadata;
        }

        $payment = SubscriptionPayment::updateOrCreate(
            [
                'subscription_member_id' => $member->id,
                'reference_month' => $referenceMonth,
            ],
            $dataToUpdate
        );

        // When payment is confirmed, add income to primary bank account
        if ($isNowPaid && ! $wasPaid) {
            $primaryAccount = $subscription->workspace->getPrimaryBankAccount();
            if ($primaryAccount) {
                $this->transactionService->create([
                    'workspace_id' => $subscription->workspace_id,
                    'created_by_user_id' => $userId ?? $subscription->workspace->owner_id,
                    'type' => TransactionType::Income,
                    'amount' => $amount,
                    'occurred_at' => $paymentDate ?? now()->toDateString(),
                    'status' => TransactionStatus::Paid,
                    'description' => "Rateio recebido: {$subscription->service_name} ({$member->name})",
                    'bank_account_id' => $primaryAccount->id,
                    'category_id' => $subscription->category_id,
                    'subscription_id' => $subscription->id,
                    'notes' => "Rateio recebido ciclo {$referenceMonth}".($pixE2EId ? " (Pix: {$pixE2EId})" : ''),
                ]);
            }
        } elseif (! $isNowPaid && $wasPaid) {
            // Revert credit from primary bank account
            $incomeTx = Transaction::where('subscription_id', $subscription->id)
                ->where('type', TransactionType::Income)
                ->where('description', 'like', "%{$member->name}%")
                ->where('occurred_at', 'like', "{$referenceMonth}%")
                ->latest('occurred_at')
                ->first();

            if ($incomeTx) {
                $this->transactionService->delete($incomeTx);
            }
        }

        return $payment;
    }
}
