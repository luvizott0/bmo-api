<?php

namespace App\Models;

use Database\Factories\SubscriptionMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['subscription_id', 'user_id', 'name', 'contact', 'installment_amount', 'is_active'])]
class SubscriptionMember extends Model
{
    /** @use HasFactory<SubscriptionMemberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'installment_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
