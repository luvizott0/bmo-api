<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\Workspace;
use Carbon\Carbon;

class SubscriptionService
{
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
}
