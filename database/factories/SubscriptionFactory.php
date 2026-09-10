<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'service_name' => fake()->randomElement(['Netflix', 'Spotify Family', 'Amazon Prime', 'YouTube Premium', 'Disney+']),
            'color_hex' => fake()->hexColor(),
            'total_amount' => 55.90,
            'billing_day' => 15,
            'is_active' => true,
        ];
    }
}
