<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\FixedBill;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedBill>
 */
class FixedBillFactory extends Factory
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
            'name' => fake()->randomElement(['Rent', 'Condo Fee', 'Electricity', 'Internet', 'Water']),
            'type' => TransactionType::Expense,
            'estimated_amount' => fake()->randomFloat(2, 100, 2000),
            'due_day' => 10,
            'is_active' => true,
            'is_reminder_active' => true,
            'reminder_days_before' => 3,
        ];
    }
}
