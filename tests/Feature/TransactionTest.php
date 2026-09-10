<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'owner_id' => $this->user->id,
        'is_personal' => true,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => WorkspaceRole::Owner->value]);
    $this->category = Category::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->account = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'current_balance' => 1000.00,
    ]);
    $this->card = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'total_limit' => 5000.00,
    ]);
});

test('paid income increases bank account balance', function () {
    $payload = [
        'type' => TransactionType::Income->value,
        'amount' => 500.00,
        'occurred_at' => '2026-09-10',
        'status' => TransactionStatus::Paid->value,
        'description' => 'Salário do mês',
        'bank_account_id' => $this->account->id,
        'category_id' => $this->category->id,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/transactions', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.type', TransactionType::Income->value)
        ->assertJsonPath('data.status', TransactionStatus::Paid->value);

    expect((float) $this->account->fresh()->current_balance)->toBe(1500.0);
});

test('paid expense decreases bank account balance', function () {
    $payload = [
        'type' => TransactionType::Expense->value,
        'amount' => 300.00,
        'occurred_at' => '2026-09-10',
        'status' => TransactionStatus::Paid->value,
        'description' => 'Supermercado',
        'bank_account_id' => $this->account->id,
        'category_id' => $this->category->id,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/transactions', $payload);

    $response->assertCreated();

    expect((float) $this->account->fresh()->current_balance)->toBe(700.0);
});

test('pending expense on bank account does not immediately decrease balance', function () {
    $payload = [
        'type' => TransactionType::Expense->value,
        'amount' => 300.00,
        'occurred_at' => '2026-09-15',
        'status' => TransactionStatus::Pending->value,
        'description' => 'Boleto agendado',
        'bank_account_id' => $this->account->id,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/transactions', $payload);

    $response->assertCreated();

    // Balance remains 1000 until paid
    expect((float) $this->account->fresh()->current_balance)->toBe(1000.0);
});

test('deleting a paid transaction reverts bank account balance', function () {
    $transaction = Transaction::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'bank_account_id' => $this->account->id,
        'credit_card_id' => null,
        'type' => TransactionType::Expense,
        'amount' => 200.00,
        'status' => TransactionStatus::Paid,
    ]);

    // Apply expense to account
    $this->account->decrement('current_balance', 200.00);
    expect((float) $this->account->fresh()->current_balance)->toBe(800.0);

    // Now delete the transaction via API
    $response = $this->actingAs($this->user)
        ->deleteJson("/api/transactions/{$transaction->id}");

    $response->assertOk();

    // Balance must be reverted back to 1000
    expect((float) $this->account->fresh()->current_balance)->toBe(1000.0);
});

test('updating paid transaction amount adjusts bank account balance delta', function () {
    $transaction = Transaction::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'bank_account_id' => $this->account->id,
        'credit_card_id' => null,
        'type' => TransactionType::Expense,
        'amount' => 100.00,
        'status' => TransactionStatus::Paid,
    ]);

    // Initial deduction
    $this->account->decrement('current_balance', 100.00);
    expect((float) $this->account->fresh()->current_balance)->toBe(900.0);

    // Update amount from 100 to 250
    $response = $this->actingAs($this->user)
        ->putJson("/api/transactions/{$transaction->id}", [
            'amount' => 250.00,
        ]);

    $response->assertOk();

    // Balance should now be 1000 - 250 = 750
    expect((float) $this->account->fresh()->current_balance)->toBe(750.0);
});

test('cannot associate transaction to both card and bank account', function () {
    $payload = [
        'type' => TransactionType::Expense->value,
        'amount' => 150.00,
        'occurred_at' => '2026-09-10',
        'status' => TransactionStatus::Pending->value,
        'description' => 'Compra inválida',
        'bank_account_id' => $this->account->id,
        'credit_card_id' => $this->card->id,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/transactions', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['bank_account_id']);
});

test('cannot associate income to credit card', function () {
    $payload = [
        'type' => TransactionType::Income->value,
        'amount' => 150.00,
        'occurred_at' => '2026-09-10',
        'status' => TransactionStatus::Pending->value,
        'description' => 'Receita no cartão inválida',
        'credit_card_id' => $this->card->id,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/transactions', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['credit_card_id']);
});

test('transactions listing returns filtered data and summary meta', function () {
    Transaction::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'type' => TransactionType::Income,
        'amount' => 3000.00,
        'occurred_at' => '2026-09-05',
        'status' => TransactionStatus::Paid,
        'bank_account_id' => $this->account->id,
    ]);

    Transaction::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'type' => TransactionType::Expense,
        'amount' => 1200.00,
        'occurred_at' => '2026-09-08',
        'status' => TransactionStatus::Paid,
        'bank_account_id' => $this->account->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/transactions?month_year=2026-09');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.summary.total_income', 3000)
        ->assertJsonPath('meta.summary.total_expenses', 1200)
        ->assertJsonPath('meta.summary.period_balance', 1800);
});

test('user can create a single expense on a credit card and reduce its available limit', function () {
    $card = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'total_limit' => 5000.00,
    ]);

    $payload = [
        'type' => 'expense',
        'amount' => 350.00,
        'occurred_at' => '2026-09-10',
        'status' => 'pending',
        'description' => 'Jantar no restaurante',
        'credit_card_id' => $card->id,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/transactions', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.credit_card_id', $card->id)
        ->assertJsonPath('data.amount', 350);

    expect((float) $card->fresh()->available_limit)->toBe(4650.0);
});
