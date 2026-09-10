<?php

namespace Database\Factories;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_member_id' => SubscriptionMember::factory(),
            'reference_month' => now()->format('Y-m'),
            'amount' => 14.00,
            'status' => SubscriptionPaymentStatus::Pending,
            'payment_date' => null,
        ];
    }
}
