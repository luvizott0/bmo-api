<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\SubscriptionMember;
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

test('user can update subscription credit card from card A to card B', function () {
    $cardA = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Nubank Violeta',
    ]);
    $cardB = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'XP Visa Infinite',
    ]);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Netflix Premium',
        'total_amount' => 59.90,
        'credit_card_id' => $cardA->id,
        'billing_day' => 15,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/subscriptions/{$subscription->id}", [
            'credit_card_id' => $cardB->id,
            'total_amount' => 65.90,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.credit_card_id', $cardB->id)
        ->assertJsonPath('data.total_amount', 65.9);

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'credit_card_id' => $cardB->id,
        'total_amount' => 65.90,
    ]);
});

test('updating subscription credit card updates pending cycle transaction', function () {
    $cardA = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Card A',
    ]);
    $cardB = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Card B',
    ]);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Spotify Família',
        'total_amount' => 34.90,
        'credit_card_id' => $cardA->id,
        'billing_day' => 10,
    ]);

    $currentMonth = now()->format('Y-m');
    $pendingTx = Transaction::create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'subscription_id' => $subscription->id,
        'credit_card_id' => $cardA->id,
        'type' => TransactionType::Expense,
        'amount' => 34.90,
        'occurred_at' => "{$currentMonth}-10",
        'status' => TransactionStatus::Pending,
        'description' => 'Assinatura: Spotify Família',
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/subscriptions/{$subscription->id}", [
            'credit_card_id' => $cardB->id,
            'service_name' => 'Spotify HiFi',
            'total_amount' => 44.90,
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('transactions', [
        'id' => $pendingTx->id,
        'credit_card_id' => $cardB->id,
        'amount' => 44.90,
        'description' => 'Assinatura: Spotify HiFi',
    ]);
});

test('user can switch subscription from credit card to bank account', function () {
    $card = CreditCard::factory()->create(['workspace_id' => $this->workspace->id]);
    $account = BankAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'credit_card_id' => $card->id,
        'bank_account_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/subscriptions/{$subscription->id}", [
            'credit_card_id' => null,
            'bank_account_id' => $account->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.credit_card_id', null)
        ->assertJsonPath('data.bank_account_id', $account->id);

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'credit_card_id' => null,
        'bank_account_id' => $account->id,
    ]);
});

test('user can update subscription members when editing', function () {
    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'total_amount' => 60.00,
    ]);

    $member1 = SubscriptionMember::factory()->create([
        'subscription_id' => $subscription->id,
        'name' => 'Member One',
        'installment_amount' => 30.00,
    ]);
    $member2 = SubscriptionMember::factory()->create([
        'subscription_id' => $subscription->id,
        'name' => 'Member Two',
        'installment_amount' => 30.00,
    ]);

    // Update member1, remove member2, and add member3
    $response = $this->actingAs($this->user)
        ->putJson("/api/subscriptions/{$subscription->id}", [
            'members' => [
                [
                    'id' => $member1->id,
                    'name' => 'Member One Updated',
                    'installment_amount' => 20.00,
                ],
                [
                    'name' => 'Member Three New',
                    'installment_amount' => 20.00,
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonCount(2, 'data.members');

    $this->assertDatabaseHas('subscription_members', [
        'id' => $member1->id,
        'name' => 'Member One Updated',
        'installment_amount' => 20.00,
    ]);

    $this->assertDatabaseMissing('subscription_members', [
        'id' => $member2->id,
    ]);

    $this->assertDatabaseHas('subscription_members', [
        'subscription_id' => $subscription->id,
        'name' => 'Member Three New',
        'installment_amount' => 20.00,
    ]);
});

test('user cannot update subscription from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignSubscription = Subscription::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'service_name' => 'Secret',
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/subscriptions/{$foreignSubscription->id}", [
            'service_name' => 'Hacked',
        ]);

    $response->assertNotFound();
});
