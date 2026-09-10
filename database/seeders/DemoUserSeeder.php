<?php

namespace Database\Seeders;

use App\Enums\BankAccountType;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FixedBill;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create or retrieve Alex User
        $user = User::firstOrCreate(
            ['email' => 'alex@flux.app'],
            [
                'name' => 'Alex',
                'password' => Hash::make('password'),
            ]
        );

        // 2. Personal Workspace
        $workspace = Workspace::firstOrCreate(
            ['owner_id' => $user->id, 'is_personal' => true],
            ['name' => 'My Personal Space']
        );

        if (! $workspace->members()->where('user_id', $user->id)->exists()) {
            $workspace->members()->attach($user->id, [
                'role' => WorkspaceRole::Owner->value,
            ]);
        }

        // 3. Seed Categories for Workspace
        CategorySeeder::seedForWorkspace($workspace);
        StockCategorySeeder::seedForWorkspace($workspace);

        $salaryCat = Category::where('workspace_id', $workspace->id)->where('name', 'Salary')->first();
        $housingCat = Category::where('workspace_id', $workspace->id)->where('name', 'Housing')->first();
        $servicesCat = Category::where('workspace_id', $workspace->id)->where('name', 'Subscriptions & Services')->first();
        $otherCat = Category::where('workspace_id', $workspace->id)->where('name', 'Other Expenses')->first();

        // 4. Bank Accounts
        $checking = BankAccount::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Chase Sapphire'],
            [
                'bank_name' => 'Chase Bank',
                'type' => BankAccountType::Checking,
                'current_balance' => 4250.00,
                'color_hex' => '#2563eb',
                'is_active' => true,
            ]
        );

        $mercury = BankAccount::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Mercury Checking'],
            [
                'bank_name' => 'Mercury Bank',
                'type' => BankAccountType::Checking,
                'current_balance' => 8420.00,
                'color_hex' => '#059669',
                'is_active' => true,
            ]
        );

        // Credit Card
        $amexCard = CreditCard::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Amex Gold'],
            [
                'bank_account_id' => $checking->id,
                'type' => 'credit',
                'brand' => 'American Express',
                'total_limit' => 15000.00,
                'closing_day' => 5,
                'due_day' => 12,
                'color_hex' => '#ea580c',
                'is_active' => true,
            ]
        );

        // Invoice expense on Amex Gold
        Transaction::firstOrCreate(
            ['workspace_id' => $workspace->id, 'description' => 'Fatura Atual Amex Gold'],
            [
                'created_by_user_id' => $user->id,
                'type' => TransactionType::Expense,
                'amount' => 1830.00,
                'occurred_at' => '2026-09-02',
                'status' => TransactionStatus::Pending,
                'credit_card_id' => $amexCard->id,
                'category_id' => $otherCat?->id,
            ]
        );

        // 5. Fixed Bills (Upcoming Bills)
        FixedBill::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Internet & WiFi'],
            [
                'type' => TransactionType::Expense,
                'estimated_amount' => 85.00,
                'due_day' => 10,
                'category_id' => $servicesCat?->id,
                'preferred_bank_account_id' => $checking->id,
                'is_active' => true,
                'is_reminder_active' => true,
                'reminder_days_before' => 3,
                'notes' => 'Fiber Internet 600MB',
            ]
        );

        FixedBill::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Rent Installment'],
            [
                'type' => TransactionType::Expense,
                'estimated_amount' => 1200.00,
                'due_day' => 13,
                'category_id' => $housingCat?->id,
                'preferred_bank_account_id' => $checking->id,
                'is_active' => true,
                'is_reminder_active' => true,
                'reminder_days_before' => 5,
                'notes' => 'Apartment monthly rent',
            ]
        );

        FixedBill::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Electricity'],
            [
                'type' => TransactionType::Expense,
                'estimated_amount' => 142.30,
                'due_day' => 22,
                'category_id' => $housingCat?->id,
                'preferred_bank_account_id' => $checking->id,
                'is_active' => true,
                'is_reminder_active' => true,
                'reminder_days_before' => 2,
                'notes' => 'Electricity utility bill',
            ]
        );

        // 6. Subscriptions
        $netflix = Subscription::firstOrCreate(
            ['workspace_id' => $workspace->id, 'service_name' => 'Netflix Family'],
            [
                'total_amount' => 17.99,
                'billing_day' => 14,
                'bank_account_id' => $checking->id,
                'category_id' => $servicesCat?->id,
                'is_active' => true,
            ]
        );

        if ($netflix->members()->count() === 0) {
            $m1 = $netflix->members()->create(['name' => 'Alex', 'contact' => 'alex@flux.app', 'installment_amount' => 4.50, 'is_active' => true]);
            $m2 = $netflix->members()->create(['name' => 'Maria', 'contact' => 'maria@flux.app', 'installment_amount' => 4.50, 'is_active' => true]);
            $m3 = $netflix->members()->create(['name' => 'John', 'contact' => 'john@flux.app', 'installment_amount' => 4.50, 'is_active' => true]);
            $m4 = $netflix->members()->create(['name' => 'Kate', 'contact' => 'kate@flux.app', 'installment_amount' => 4.49, 'is_active' => true]);

            $m1->payments()->create(['reference_month' => '2026-09', 'amount' => 4.50, 'status' => SubscriptionPaymentStatus::Paid, 'payment_date' => '2026-09-02']);
            $m2->payments()->create(['reference_month' => '2026-09', 'amount' => 4.50, 'status' => SubscriptionPaymentStatus::Paid, 'payment_date' => '2026-09-03']);
            $m3->payments()->create(['reference_month' => '2026-09', 'amount' => 4.50, 'status' => SubscriptionPaymentStatus::Pending]);
            $m4->payments()->create(['reference_month' => '2026-09', 'amount' => 4.49, 'status' => SubscriptionPaymentStatus::Pending]);
        }

        $spotify = Subscription::firstOrCreate(
            ['workspace_id' => $workspace->id, 'service_name' => 'Spotify Duo'],
            [
                'total_amount' => 14.99,
                'billing_day' => 22,
                'bank_account_id' => $checking->id,
                'category_id' => $servicesCat?->id,
                'is_active' => true,
            ]
        );

        if ($spotify->members()->count() === 0) {
            $s1 = $spotify->members()->create(['name' => 'Alex', 'contact' => 'alex@flux.app', 'installment_amount' => 7.50, 'is_active' => true]);
            $s2 = $spotify->members()->create(['name' => 'Beatriz', 'contact' => 'beatriz@flux.app', 'installment_amount' => 7.49, 'is_active' => true]);

            $s1->payments()->create(['reference_month' => '2026-09', 'amount' => 7.50, 'status' => SubscriptionPaymentStatus::Paid, 'payment_date' => '2026-09-01']);
            $s2->payments()->create(['reference_month' => '2026-09', 'amount' => 7.49, 'status' => SubscriptionPaymentStatus::Paid, 'payment_date' => '2026-09-01']);
        }

        // 7. Transactions
        Transaction::firstOrCreate(
            ['workspace_id' => $workspace->id, 'description' => 'Monthly Salary'],
            [
                'created_by_user_id' => $user->id,
                'type' => TransactionType::Income,
                'amount' => 5050.00,
                'occurred_at' => '2026-09-05',
                'status' => TransactionStatus::Paid,
                'bank_account_id' => $checking->id,
                'category_id' => $salaryCat?->id,
            ]
        );

        Transaction::firstOrCreate(
            ['workspace_id' => $workspace->id, 'description' => 'Groceries and Snacks'],
            [
                'created_by_user_id' => $user->id,
                'type' => TransactionType::Expense,
                'amount' => 201.89,
                'occurred_at' => '2026-09-08',
                'status' => TransactionStatus::Paid,
                'bank_account_id' => $checking->id,
                'category_id' => $otherCat?->id,
            ]
        );

        // Previous months transactions for quarterly performance
        Transaction::firstOrCreate(
            ['workspace_id' => $workspace->id, 'description' => 'July Salary'],
            [
                'created_by_user_id' => $user->id,
                'type' => TransactionType::Income,
                'amount' => 4300.00,
                'occurred_at' => '2026-07-05',
                'status' => TransactionStatus::Paid,
                'bank_account_id' => $checking->id,
                'category_id' => $salaryCat?->id,
            ]
        );

        Transaction::firstOrCreate(
            ['workspace_id' => $workspace->id, 'description' => 'July General Expenses'],
            [
                'created_by_user_id' => $user->id,
                'type' => TransactionType::Expense,
                'amount' => 1450.00,
                'occurred_at' => '2026-07-20',
                'status' => TransactionStatus::Paid,
                'bank_account_id' => $checking->id,
                'category_id' => $otherCat?->id,
            ]
        );

        Transaction::firstOrCreate(
            ['workspace_id' => $workspace->id, 'description' => 'August Salary'],
            [
                'created_by_user_id' => $user->id,
                'type' => TransactionType::Income,
                'amount' => 4100.00,
                'occurred_at' => '2026-08-05',
                'status' => TransactionStatus::Paid,
                'bank_account_id' => $checking->id,
                'category_id' => $salaryCat?->id,
            ]
        );

        Transaction::firstOrCreate(
            ['workspace_id' => $workspace->id, 'description' => 'August General Expenses'],
            [
                'created_by_user_id' => $user->id,
                'type' => TransactionType::Expense,
                'amount' => 1300.00,
                'occurred_at' => '2026-08-18',
                'status' => TransactionStatus::Paid,
                'bank_account_id' => $checking->id,
                'category_id' => $otherCat?->id,
            ]
        );
    }
}
