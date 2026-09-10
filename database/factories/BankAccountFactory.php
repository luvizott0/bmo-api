<?php

namespace Database\Factories;

use App\Enums\BankAccountType;
use App\Models\BankAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
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
            'bank_name' => fake()->randomElement(['Chase', 'Bank of America', 'Wells Fargo', 'Citibank', 'Capital One']),
            'name' => 'Checking Account',
            'type' => BankAccountType::Checking,
            'current_balance' => fake()->randomFloat(2, 100, 5000),
            'color_hex' => fake()->hexColor(),
            'is_active' => true,
        ];
    }
}
