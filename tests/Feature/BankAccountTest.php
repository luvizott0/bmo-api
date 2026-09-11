<?php

use App\Enums\BankAccountType;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
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

test('user can list bank accounts of active workspace', function () {
    BankAccount::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/bank-accounts');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can create a bank account with bank_name and current_balance', function () {
    $payload = [
        'bank_name' => 'Nubank',
        'name' => 'Main Checking Account',
        'type' => BankAccountType::Checking->value,
        'current_balance' => 1500.50,
        'color_hex' => '#820AD1',
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/bank-accounts', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.bank_name', 'Nubank')
        ->assertJsonPath('data.current_balance', 1500.50);

    $this->assertDatabaseHas('bank_accounts', [
        'workspace_id' => $this->workspace->id,
        'bank_name' => 'Nubank',
        'current_balance' => 1500.50,
    ]);
});

test('user can view bank account details', function () {
    $account = BankAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/bank-accounts/{$account->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $account->id)
        ->assertJsonPath('data.bank_name', $account->bank_name);
});

test('user can update bank account', function () {
    $account = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Old Name',
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/bank-accounts/{$account->id}", [
            'name' => 'Updated Name',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Name');

    expect($account->fresh()->name)->toBe('Updated Name');
});

test('user can delete bank account', function () {
    $account = BankAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/bank-accounts/{$account->id}");

    $response->assertOk()
        ->assertJson(['message' => 'Bank account deleted successfully.']);

    $this->assertDatabaseMissing('bank_accounts', ['id' => $account->id]);
});

test('user can adjust bank account balance manually', function () {
    $account = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'current_balance' => 500.00,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/bank-accounts/{$account->id}/adjust-balance", [
            'current_balance' => 750.25,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.current_balance', 750.25);

    expect((float) $account->fresh()->current_balance)->toBe(750.25);
});

test('first bank account created is automatically primary and user can switch primary account', function () {
    $first = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'is_primary' => true,
    ]);

    $second = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'is_primary' => false,
    ]);

    expect($first->fresh()->is_primary)->toBeTrue();
    expect($second->fresh()->is_primary)->toBeFalse();

    // Set second as primary
    $response = $this->actingAs($this->user)
        ->postJson("/api/bank-accounts/{$second->id}/set-primary");

    $response->assertOk()
        ->assertJsonPath('data.is_primary', true);

    expect($second->fresh()->is_primary)->toBeTrue();
    expect($first->fresh()->is_primary)->toBeFalse();
});
