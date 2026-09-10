<?php

namespace Database\Factories;

use App\Models\CreditCard;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
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
            'name' => fake()->randomElement(['Chase Sapphire', 'Amex Gold', 'Capital One Venture', 'Citi Double Cash']),
            'total_limit' => 5000.00,
            'closing_day' => 5,
            'due_day' => 12,
            'brand' => 'Mastercard',
            'color_hex' => '#820AD1',
            'is_active' => true,
        ];
    }
}
