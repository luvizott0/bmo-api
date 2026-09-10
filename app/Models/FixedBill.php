<?php

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\FixedBillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'name',
    'type',
    'estimated_amount',
    'due_day',
    'category_id',
    'preferred_bank_account_id',
    'is_active',
    'is_reminder_active',
    'reminder_days_before',
    'notes',
])]
class FixedBill extends Model
{
    /** @use HasFactory<FixedBillFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'estimated_amount' => 'decimal:2',
            'due_day' => 'integer',
            'reminder_days_before' => 'integer',
            'is_active' => 'boolean',
            'is_reminder_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function preferredBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'preferred_bank_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
