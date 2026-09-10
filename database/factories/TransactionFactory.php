<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'created_by_user_id' => User::factory(),
            'type' => TransactionType::Expense,
            'amount' => fake()->randomFloat(2, 10, 500),
            'occurred_at' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'status' => TransactionStatus::Paid,
            'description' => fake()->sentence(3),
            'bank_account_id' => BankAccount::factory(),
            'credit_card_id' => null,
            'category_id' => null,
            'notes' => null,
        ];
    }
}
