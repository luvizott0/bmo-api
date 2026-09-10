<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FixedBill;
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
        'current_balance' => 2500.00,
    ]);
});

test('user can list fixed bills of workspace and filter by active status', function () {
    FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Rent',
        'is_active' => true,
    ]);

    FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Old Gym',
        'is_active' => false,
    ]);

    // By default, only active bills are returned
    $response = $this->actingAs($this->user)
        ->getJson('/api/fixed-bills');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Rent');

    // With active_only=0, all bills returned
    $allResponse = $this->actingAs($this->user)
        ->getJson('/api/fixed-bills?active_only=0');

    $allResponse->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user can create a fixed bill with custom color', function () {
    $payload = [
        'name' => 'Fiber Internet',
        'color_hex' => '#0ea5e9',
        'type' => TransactionType::Expense->value,
        'estimated_amount' => 129.90,
        'due_day' => 15,
        'category_id' => $this->category->id,
        'preferred_bank_account_id' => $this->account->id,
        'is_reminder_active' => true,
        'reminder_days_before' => 3,
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/fixed-bills', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Fiber Internet')
        ->assertJsonPath('data.color_hex', '#0ea5e9')
        ->assertJsonPath('data.estimated_amount', 129.9)
        ->assertJsonPath('data.due_day', 15);

    $this->assertDatabaseHas('fixed_bills', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Fiber Internet',
        'color_hex' => '#0ea5e9',
    ]);
});

test('user can view a fixed bill details', function () {
    $bill = FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Condo Fee',
        'estimated_amount' => 450.00,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/fixed-bills/{$bill->id}");

    $response->assertOk()
        ->assertJsonPath('data.name', 'Condo Fee')
        ->assertJsonPath('data.estimated_amount', 450);
});

test('user cannot view a fixed bill from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignBill = FixedBill::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/fixed-bills/{$foreignBill->id}");

    $response->assertNotFound();
});

test('user can update a fixed bill', function () {
    $bill = FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Electricity',
        'estimated_amount' => 180.00,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/fixed-bills/{$bill->id}", [
            'name' => 'Electricity Copel',
            'estimated_amount' => 210.00,
            'due_day' => 20,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Electricity Copel')
        ->assertJsonPath('data.estimated_amount', 210);

    $this->assertDatabaseHas('fixed_bills', [
        'id' => $bill->id,
        'name' => 'Electricity Copel',
        'estimated_amount' => 210.00,
    ]);
});

test('user can delete a fixed bill', function () {
    $bill = FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/fixed-bills/{$bill->id}");

    $response->assertOk()
        ->assertJsonPath('message', 'Fixed bill deleted successfully.');

    $this->assertDatabaseMissing('fixed_bills', [
        'id' => $bill->id,
    ]);
});

test('user can liquidate/pay a fixed bill and account balance is decremented', function () {
    $bill = FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Apartment Rent',
        'type' => TransactionType::Expense,
        'estimated_amount' => 1200.00,
        'preferred_bank_account_id' => $this->account->id,
        'category_id' => $this->category->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/fixed-bills/{$bill->id}/pay", [
            'amount' => 1200.00,
            'payment_date' => '2026-09-10',
            'bank_account_id' => $this->account->id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Fixed bill paid successfully.')
        ->assertJsonPath('transaction.type', TransactionType::Expense->value)
        ->assertJsonPath('transaction.status', TransactionStatus::Paid->value)
        ->assertJsonPath('transaction.fixed_bill_id', $bill->id);

    // Initial account balance was 2500, after 1200 payment it should be 1300
    expect((float) $this->account->fresh()->current_balance)->toBe(1300.0);

    $this->assertDatabaseHas('transactions', [
        'workspace_id' => $this->workspace->id,
        'fixed_bill_id' => $bill->id,
        'amount' => 1200.00,
        'status' => TransactionStatus::Paid->value,
    ]);
});

test('cannot liquidate fixed bill linking both bank account and credit card', function () {
    $bill = FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => TransactionType::Expense,
    ]);

    $card = CreditCard::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/fixed-bills/{$bill->id}/pay", [
            'bank_account_id' => $this->account->id,
            'credit_card_id' => $card->id,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['bank_account_id']);
});

test('user can unpay fixed bill and account balance is restored', function () {
    $bill = FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Electricity Copel',
        'type' => TransactionType::Expense,
        'estimated_amount' => 180.00,
        'preferred_bank_account_id' => $this->account->id,
    ]);

    // Pay the bill first (2500 - 180 = 2320)
    $this->actingAs($this->user)
        ->postJson("/api/fixed-bills/{$bill->id}/pay", [
            'amount' => 180.00,
            'payment_date' => '2026-09-10',
            'bank_account_id' => $this->account->id,
        ])
        ->assertCreated();

    expect((float) $this->account->fresh()->current_balance)->toBe(2320.0);

    // Now unpay
    $unpayResponse = $this->actingAs($this->user)
        ->postJson("/api/fixed-bills/{$bill->id}/unpay", [
            'reference_month' => '2026-09',
        ]);

    $unpayResponse->assertOk()
        ->assertJsonPath('message', 'Fixed bill marked as unpaid.')
        ->assertJsonPath('bill.is_paid', false);

    // Balance restored to 2500
    expect((float) $this->account->fresh()->current_balance)->toBe(2500.0);
});

test('artisan command check due bills processes approaching bills', function () {
    $todayDay = (int) now()->day;

    FixedBill::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Bill Due Today',
        'due_day' => $todayDay,
        'is_reminder_active' => true,
        'is_active' => true,
    ]);

    $this->artisan('financial:check-due-bills')
        ->expectsOutputToContain('Bill Due Today')
        ->assertSuccessful();
});
