<?php

namespace App\Models;

use App\Enums\BankAccountType;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'user_id', 'bank_name', 'name', 'type', 'current_balance', 'color_hex', 'is_active', 'is_primary', 'is_shared'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => BankAccountType::class,
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'is_primary' => 'boolean',
            'is_shared' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'bank_account_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(CreditCard::class, 'bank_account_id');
    }
}
