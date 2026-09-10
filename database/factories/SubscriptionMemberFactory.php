<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionMember>
 */
class SubscriptionMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'name' => fake()->firstName(),
            'contact' => fake()->phoneNumber(),
            'installment_amount' => 14.00,
            'is_active' => true,
        ];
    }
}
