<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionService
{
    /**
     * Create a new transaction and update bank account balance if paid.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data): Transaction {
            $transaction = Transaction::create($data);

            $this->applyBalance($transaction);

            return $transaction;
        });
    }

    /**
     * Create installment transactions on a credit card.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Transaction>
     */
    public function createInstallments(array $data, int $installmentsCount): Collection
    {
        return DB::transaction(function () use ($data, $installmentsCount) {
            $totalAmount = (float) $data['amount'];
            $baseAmount = floor(($totalAmount / $installmentsCount) * 100) / 100;
            $remainder = round($totalAmount - ($baseAmount * $installmentsCount), 2);
            $groupId = (string) Str::uuid();

            $card = ! empty($data['credit_card_id']) ? CreditCard::find($data['credit_card_id']) : null;
            $occurredAt = Carbon::parse($data['occurred_at'] ?? now());

            $firstInvoiceMonth = $occurredAt->copy();
            if ($card && $occurredAt->day >= $card->closing_day) {
                $firstInvoiceMonth->addMonth();
            }

            $transactions = new Collection;

            for ($i = 1; $i <= $installmentsCount; $i++) {
                $installmentMonth = $firstInvoiceMonth->copy()->addMonths($i - 1);
                $dueDay = $card ? min($card->due_day, (int) $installmentMonth->daysInMonth) : (int) $installmentMonth->day;
                $installmentDueDate = $installmentMonth->copy()->day($dueDay);

                $installmentAmount = $i === 1 ? round($baseAmount + $remainder, 2) : $baseAmount;

                $installmentData = array_merge($data, [
                    'amount' => $installmentAmount,
                    'occurred_at' => $installmentDueDate->format('Y-m-d'),
                    'status' => TransactionStatus::Pending,
                    'description' => "{$data['description']} ({$i}/{$installmentsCount})",
                    'installment_number' => $i,
                    'total_installments' => $installmentsCount,
                    'installment_group_id' => $groupId,
                ]);

                unset($installmentData['is_installment'], $installmentData['installments_count']);

                $tx = Transaction::create($installmentData);
                $transactions->push($tx);
            }

            return $transactions;
        });
    }

    /**
     * Update an existing transaction and adjust bank account balances.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data): Transaction {
            // Revert original transaction balance impact
            $this->revertBalance($transaction);

            // Update attributes
            $transaction->update($data);
            $transaction->refresh();

            // Apply new balance impact
            $this->applyBalance($transaction);

            return $transaction;
        });
    }

    /**
     * Delete a transaction and revert its balance impact.
     */
    public function delete(Transaction $transaction): bool
    {
        return DB::transaction(function () use ($transaction): bool {
            $this->revertBalance($transaction);

            return (bool) $transaction->delete();
        });
    }

    /**
     * Apply transaction amount to bank account balance.
     */
    private function applyBalance(Transaction $transaction): void
    {
        if ($transaction->status === TransactionStatus::Paid && $transaction->bank_account_id) {
            $account = BankAccount::find($transaction->bank_account_id);

            if ($account) {
                if ($transaction->type === TransactionType::Income) {
                    $account->increment('current_balance', $transaction->amount);
                } else {
                    $account->decrement('current_balance', $transaction->amount);
                }
            }
        }
    }

    /**
     * Revert transaction amount from bank account balance.
     */
    private function revertBalance(Transaction $transaction): void
    {
        if ($transaction->status === TransactionStatus::Paid && $transaction->bank_account_id) {
            $account = BankAccount::find($transaction->bank_account_id);

            if ($account) {
                if ($transaction->type === TransactionType::Income) {
                    $account->decrement('current_balance', $transaction->amount);
                } else {
                    $account->increment('current_balance', $transaction->amount);
                }
            }
        }
    }
}
