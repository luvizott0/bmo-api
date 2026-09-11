<?php

use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can create a bank account with user_id and is_shared', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

    $response = $this->actingAs($user)
        ->withHeader('X-Workspace-Id', (string) $workspace->id)
        ->postJson('/api/bank-accounts', [
            'name' => 'Nubank Principal',
            'bank_name' => 'Nubank',
            'type' => 'checking',
            'current_balance' => 1500.50,
            'color_hex' => '#8b5cf6',
            'is_shared' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Nubank Principal')
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.is_shared', true)
        ->assertJsonPath('data.user.name', $user->name);

    $this->assertDatabaseHas('bank_accounts', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'name' => 'Nubank Principal',
        'is_shared' => true,
    ]);
});

test('user can update an existing bank account', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

    $account = BankAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'name' => 'Conta Antiga',
        'bank_name' => 'Banco X',
        'color_hex' => '#2563eb',
        'is_shared' => true,
    ]);

    $response = $this->actingAs($user)
        ->withHeader('X-Workspace-Id', (string) $workspace->id)
        ->putJson("/api/bank-accounts/{$account->id}", [
            'name' => 'Conta Atualizada',
            'bank_name' => 'Banco Y',
            'color_hex' => '#10b981',
            'is_shared' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Conta Atualizada')
        ->assertJsonPath('data.bank_name', 'Banco Y')
        ->assertJsonPath('data.color_hex', '#10b981')
        ->assertJsonPath('data.is_shared', false);

    expect($account->fresh()->name)->toBe('Conta Atualizada');
    expect($account->fresh()->is_shared)->toBeFalse();
});

test('user can create a credit card with user_id and is_shared', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
    $account = BankAccount::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->withHeader('X-Workspace-Id', (string) $workspace->id)
        ->postJson('/api/credit-cards', [
            'bank_account_id' => $account->id,
            'name' => 'Cartão Black',
            'brand' => 'Mastercard',
            'type' => 'credit',
            'total_limit' => 15000.00,
            'closing_day' => 10,
            'due_day' => 17,
            'color_hex' => '#0f172a',
            'is_shared' => false,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Cartão Black')
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.is_shared', false)
        ->assertJsonPath('data.user.name', $user->name);

    $this->assertDatabaseHas('credit_cards', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'name' => 'Cartão Black',
        'is_shared' => false,
    ]);
});

test('user can update an existing credit card', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
    $account = BankAccount::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    $card = CreditCard::factory()->create([
        'workspace_id' => $workspace->id,
        'bank_account_id' => $account->id,
        'user_id' => $user->id,
        'name' => 'Cartão Original',
        'total_limit' => 5000,
        'closing_day' => 5,
        'due_day' => 12,
        'is_shared' => true,
    ]);

    $response = $this->actingAs($user)
        ->withHeader('X-Workspace-Id', (string) $workspace->id)
        ->putJson("/api/credit-cards/{$card->id}", [
            'name' => 'Cartão Platinum Editado',
            'total_limit' => 8000,
            'closing_day' => 8,
            'due_day' => 15,
            'is_shared' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Cartão Platinum Editado')
        ->assertJsonPath('data.total_limit', 8000)
        ->assertJsonPath('data.closing_day', 8)
        ->assertJsonPath('data.due_day', 15)
        ->assertJsonPath('data.is_shared', false);

    expect($card->fresh()->name)->toBe('Cartão Platinum Editado');
    expect((float) $card->fresh()->total_limit)->toBe(8000.0);
    expect($card->fresh()->is_shared)->toBeFalse();
});

test('members can only view shared accounts or their own private accounts', function () {
    $owner = User::factory()->create(['name' => 'Calebe']);
    $member = User::factory()->create(['name' => 'Maria']);

    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach([
        $owner->id => ['role' => WorkspaceRole::Owner->value],
        $member->id => ['role' => WorkspaceRole::Member->value],
    ]);

    // Owner accounts
    BankAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Conta Compartilhada Calebe',
        'is_shared' => true,
    ]);
    BankAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Conta Secreta Calebe',
        'is_shared' => false,
    ]);

    // Member accounts
    BankAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'name' => 'Conta Compartilhada Maria',
        'is_shared' => true,
    ]);
    BankAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'name' => 'Conta Secreta Maria',
        'is_shared' => false,
    ]);

    // Member cards
    CreditCard::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'name' => 'Cartão Compartilhado Maria',
        'is_shared' => true,
    ]);
    CreditCard::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'name' => 'Cartão Secreto Maria',
        'is_shared' => false,
    ]);

    // When Member queries accounts: sees own accounts (shared + private) and owner's shared. Does NOT see owner's private.
    $memberResponse = $this->actingAs($member)
        ->withHeader('X-Workspace-Id', (string) $workspace->id)
        ->getJson('/api/bank-accounts');

    $memberResponse->assertOk();
    $memberAccountNames = collect($memberResponse->json('data'))->pluck('name');

    expect($memberAccountNames)->toContain('Conta Compartilhada Calebe')
        ->toContain('Conta Compartilhada Maria')
        ->toContain('Conta Secreta Maria')
        ->not->toContain('Conta Secreta Calebe');

    // When Owner queries cards: sees member's shared card, but NOT member's private card.
    $ownerCardResponse = $this->actingAs($owner)
        ->withHeader('X-Workspace-Id', (string) $workspace->id)
        ->getJson('/api/credit-cards');

    $ownerCardResponse->assertOk();
    $ownerCardNames = collect($ownerCardResponse->json('data'))->pluck('name');

    expect($ownerCardNames)->toContain('Cartão Compartilhado Maria')
        ->not->toContain('Cartão Secreto Maria');
});

test('user can list workspace members', function () {
    $owner = User::factory()->create(['name' => 'Calebe', 'email' => 'calebe@example.com']);
    $member = User::factory()->create(['name' => 'Maria', 'email' => 'maria@example.com']);

    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach([
        $owner->id => ['role' => WorkspaceRole::Owner->value],
        $member->id => ['role' => WorkspaceRole::Member->value],
    ]);

    $response = $this->actingAs($member)
        ->getJson("/api/workspaces/{$workspace->id}/members");

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'Calebe', 'email' => 'calebe@example.com'])
        ->assertJsonFragment(['name' => 'Maria', 'email' => 'maria@example.com']);
});
