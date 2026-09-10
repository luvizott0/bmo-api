<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
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
});

test('user can create a credit card with all required fields', function () {
    $payload = [
        'name' => 'Nubank Ultravioleta',
        'total_limit' => 10000.00,
        'closing_day' => 5,
        'due_day' => 12,
        'brand' => 'Mastercard',
        'color_hex' => '#820AD1',
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/credit-cards', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Nubank Ultravioleta')
        ->assertJsonPath('data.total_limit', 10000)
        ->assertJsonPath('data.available_limit', 10000)
        ->assertJsonPath('data.closing_day', 5)
        ->assertJsonPath('data.due_day', 12);

    $this->assertDatabaseHas('credit_cards', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Nubank Ultravioleta',
        'total_limit' => 10000.00,
    ]);
});

test('available_limit is correctly calculated deducting unpaid transactions', function () {
    $card = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'total_limit' => 5000.00,
    ]);

    // Check initial limit
    expect($card->available_limit)->toBe(5000.0);

    // Add unpaid (pending) transaction on this card
    Transaction::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'credit_card_id' => $card->id,
        'bank_account_id' => null,
        'type' => TransactionType::Expense,
        'amount' => 1200.00,
        'status' => TransactionStatus::Pending,
    ]);

    // Add another unpaid transaction
    Transaction::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'credit_card_id' => $card->id,
        'bank_account_id' => null,
        'type' => TransactionType::Expense,
        'amount' => 300.00,
        'status' => TransactionStatus::Pending,
    ]);

    // Add a PAID transaction on this card (e.g. previous invoice paid or installment paid)
    Transaction::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'credit_card_id' => $card->id,
        'bank_account_id' => null,
        'type' => TransactionType::Expense,
        'amount' => 500.00,
        'status' => TransactionStatus::Paid,
    ]);

    // Available limit should be: 5000 - 1200 - 300 = 3500.00 (paid 500 does not reduce available limit)
    expect($card->fresh()->available_limit)->toBe(3500.0);

    // API resource reflects the calculated attribute
    $response = $this->actingAs($this->user)
        ->getJson("/api/credit-cards/{$card->id}");

    $response->assertOk()
        ->assertJsonPath('data.total_limit', 5000)
        ->assertJsonPath('data.available_limit', 3500);
});

test('user can update credit card', function () {
    $card = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Cartao Antigo',
        'total_limit' => 2000.00,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/credit-cards/{$card->id}", [
            'name' => 'Cartao Novo',
            'total_limit' => 4000.00,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Cartao Novo')
        ->assertJsonPath('data.total_limit', 4000);
});

test('user can delete credit card', function () {
    $card = CreditCard::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/credit-cards/{$card->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('credit_cards', ['id' => $card->id]);
});

test('user can create a card linked to a bank account with type', function () {
    $bankAccount = BankAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $payload = [
        'name' => 'Cartao Inter Debito',
        'bank_account_id' => $bankAccount->id,
        'type' => 'debit',
        'daily_limit' => 2500.00,
        'brand' => 'Mastercard',
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/credit-cards', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Cartao Inter Debito')
        ->assertJsonPath('data.type', 'debit')
        ->assertJsonPath('data.bank_account_id', $bankAccount->id)
        ->assertJsonPath('data.daily_limit', 2500);

    $this->assertDatabaseHas('credit_cards', [
        'name' => 'Cartao Inter Debito',
        'bank_account_id' => $bankAccount->id,
        'type' => 'debit',
    ]);
});

test('user can retrieve monthly limits projection for a credit card', function () {
    $card = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'total_limit' => 10000.00,
        'due_day' => 15,
        'closing_day' => 7,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/credit-cards/{$card->id}/monthly-limits?months=6");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'month_year',
                    'month_name',
                    'due_date',
                    'invoice_amount',
                    'blocked_limit',
                    'available_limit',
                    'utilization_percentage',
                ],
            ],
            'card',
        ]);

    expect(count($response->json('data')))->toBe(6);
});

test('user can create installment purchase that distributes installments across months', function () {
    $card = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'total_limit' => 5000.00,
        'closing_day' => 10,
        'due_day' => 20,
    ]);

    $payload = [
        'type' => 'expense',
        'amount' => 1200.00,
        'occurred_at' => now()->startOfMonth()->day(5)->format('Y-m-d'),
        'status' => 'pending',
        'description' => 'Notebook Dell',
        'credit_card_id' => $card->id,
        'is_installment' => true,
        'installments_count' => 3,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/transactions', $payload);

    $response->assertCreated();

    // 3 transactions created
    $this->assertDatabaseCount('transactions', 3);
    $this->assertDatabaseHas('transactions', [
        'description' => 'Notebook Dell (1/3)',
        'amount' => 400.00,
        'installment_number' => 1,
        'total_installments' => 3,
    ]);
    $this->assertDatabaseHas('transactions', [
        'description' => 'Notebook Dell (2/3)',
        'amount' => 400.00,
        'installment_number' => 2,
        'total_installments' => 3,
    ]);
    $this->assertDatabaseHas('transactions', [
        'description' => 'Notebook Dell (3/3)',
        'amount' => 400.00,
        'installment_number' => 3,
        'total_installments' => 3,
    ]);

    // Available limit on card should be reduced by full 1200: 5000 - 1200 = 3800
    expect($card->fresh()->available_limit)->toBe(3800.0);
});
