<?php

namespace App\Models;

use App\Enums\SubscriptionPaymentStatus;
use Database\Factories\SubscriptionPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_member_id', 'reference_month', 'amount', 'status', 'payment_date'])]
class SubscriptionPayment extends Model
{
    /** @use HasFactory<SubscriptionPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionPaymentStatus::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SubscriptionMember::class, 'subscription_member_id');
    }
}
