<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Carbon\Carbon;
use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'bank_account_id', 'name', 'type', 'total_limit', 'daily_limit', 'closing_day', 'due_day', 'brand', 'color_hex', 'is_active'])]
class CreditCard extends Model
{
    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    protected $appends = ['available_limit'];

    protected function casts(): array
    {
        return [
            'total_limit' => 'decimal:2',
            'daily_limit' => 'decimal:2',
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Calculated available limit: total limit - sum of unpaid (pending) expense transactions.
     */
    protected function availableLimit(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                $unpaidExpenses = (float) $this->transactions()
                    ->where('status', TransactionStatus::Pending)
                    ->where('type', TransactionType::Expense)
                    ->sum('amount');

                return round((float) $this->total_limit - $unpaidExpenses, 2);
            }
        );
    }

    /**
     * Calculate monthly limit and invoice evolution projection.
     *
     * @return array<int, array{
     *     month_year: string,
     *     month_name: string,
     *     due_date: string,
     *     invoice_amount: float,
     *     blocked_limit: float,
     *     available_limit: float,
     *     utilization_percentage: float
     * }>
     */
    public function calculateMonthlyLimits(int $months = 12): array
    {
        $now = now()->startOfMonth();
        $results = [];

        $pendingExpenses = $this->transactions()
            ->where('status', TransactionStatus::Pending)
            ->where('type', TransactionType::Expense)
            ->get();

        $totalLimit = (float) $this->total_limit;

        for ($i = 0; $i < $months; $i++) {
            $monthDate = $now->copy()->addMonths($i);
            $monthYear = $monthDate->format('Y-m');
            $monthStart = $monthDate->copy()->startOfMonth();
            $monthEnd = $monthDate->copy()->endOfMonth();

            $dueDay = min($this->due_day, (int) $monthDate->daysInMonth);
            $dueDate = $monthDate->copy()->day($dueDay)->format('Y-m-d');

            $monthExpenses = $pendingExpenses->filter(function (Transaction $tx) use ($monthStart, $monthEnd) {
                $txDate = $tx->occurred_at ? Carbon::parse($tx->occurred_at) : null;

                return $txDate && $txDate->betweenIncluded($monthStart, $monthEnd);
            });

            $invoiceAmount = round((float) $monthExpenses->sum('amount'), 2);

            $remainingBlockedExpenses = $pendingExpenses->filter(function (Transaction $tx) use ($monthStart) {
                $txDate = $tx->occurred_at ? Carbon::parse($tx->occurred_at) : null;

                return $txDate && $txDate->gte($monthStart);
            });

            $blockedLimit = round((float) $remainingBlockedExpenses->sum('amount'), 2);
            $availableLimit = max(0.0, round($totalLimit - $blockedLimit, 2));
            $utilizationPercentage = $totalLimit > 0 ? min(100.0, round(($blockedLimit / $totalLimit) * 100, 1)) : 0.0;

            $ptMonths = [
                1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
            ];
            $monthName = $ptMonths[(int) $monthDate->format('n')].' '.$monthDate->format('Y');

            $results[] = [
                'month_year' => $monthYear,
                'month_name' => $monthName,
                'due_date' => $dueDate,
                'invoice_amount' => $invoiceAmount,
                'blocked_limit' => $blockedLimit,
                'available_limit' => $availableLimit,
                'utilization_percentage' => $utilizationPercentage,
            ];
        }

        return $results;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'credit_card_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'credit_card_id');
    }
}
