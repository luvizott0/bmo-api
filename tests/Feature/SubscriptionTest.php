<?php

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
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

test('user can list subscriptions belonging to active workspace', function () {
    Subscription::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $otherWorkspace = Workspace::factory()->create();
    Subscription::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/subscriptions');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user can create a subscription with initial members and custom color', function () {
    $payload = [
        'service_name' => 'Netflix 4K Família',
        'color_hex' => '#e50914',
        'total_amount' => 59.90,
        'billing_day' => 12,
        'notes' => 'Shared with friends',
        'members' => [
            [
                'name' => 'Alice',
                'installment_amount' => 20.00,
                'contact' => 'alice@test.com',
            ],
            [
                'name' => 'Bob',
                'installment_amount' => 20.00,
                'contact' => 'bob@test.com',
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/subscriptions', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.service_name', 'Netflix 4K Família')
        ->assertJsonPath('data.color_hex', '#e50914')
        ->assertJsonCount(2, 'data.members');

    $this->assertDatabaseHas('subscriptions', [
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Netflix 4K Família',
        'color_hex' => '#e50914',
    ]);

    $this->assertDatabaseHas('subscription_members', [
        'name' => 'Alice',
        'installment_amount' => 20.00,
    ]);
});

test('user can show subscription details with members', function () {
    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Spotify Duo',
    ]);

    SubscriptionMember::factory()->create([
        'subscription_id' => $subscription->id,
        'name' => 'Carol',
        'installment_amount' => 17.50,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/subscriptions/{$subscription->id}");

    $response->assertOk()
        ->assertJsonPath('data.service_name', 'Spotify Duo')
        ->assertJsonCount(1, 'data.members')
        ->assertJsonPath('data.members.0.name', 'Carol');
});

test('user cannot view subscription from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignSubscription = Subscription::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/subscriptions/{$foreignSubscription->id}");

    $response->assertNotFound();
});

test('user can update subscription', function () {
    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Disney Plus Antigo',
        'total_amount' => 33.90,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/subscriptions/{$subscription->id}", [
            'service_name' => 'Disney Plus Novo Preço',
            'total_amount' => 43.90,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.service_name', 'Disney Plus Novo Preço')
        ->assertJsonPath('data.total_amount', 43.9);

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'service_name' => 'Disney Plus Novo Preço',
    ]);
});

test('user can delete subscription', function () {
    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/subscriptions/{$subscription->id}");

    $response->assertOk()
        ->assertJsonPath('message', 'Subscription deleted successfully.');

    $this->assertDatabaseMissing('subscriptions', [
        'id' => $subscription->id,
    ]);
});

test('user can add and remove members on a subscription', function () {
    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    // Add member
    $addResponse = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$subscription->id}/members", [
            'name' => 'David',
            'installment_amount' => 15.00,
            'contact' => 'david@test.com',
        ]);

    $addResponse->assertCreated()
        ->assertJsonPath('data.name', 'David')
        ->assertJsonPath('data.installment_amount', 15);

    $memberId = $addResponse->json('data.id');

    // Remove member
    $removeResponse = $this->actingAs($this->user)
        ->deleteJson("/api/subscriptions/{$subscription->id}/members/{$memberId}");

    $removeResponse->assertOk()
        ->assertJsonPath('message', 'Member removed successfully.');

    $this->assertDatabaseMissing('subscription_members', [
        'id' => $memberId,
    ]);
});

test('user can record and update payment status for a member in a cycle', function () {
    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $member = SubscriptionMember::factory()->create([
        'subscription_id' => $subscription->id,
        'name' => 'Eva',
        'installment_amount' => 25.00,
    ]);

    // Record payment as Paid for 2026-09
    $response = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$subscription->id}/members/{$member->id}/payments", [
            'reference_month' => '2026-09',
            'status' => SubscriptionPaymentStatus::Paid->value,
            'amount' => 25.00,
            'payment_date' => '2026-09-05',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.reference_month', '2026-09')
        ->assertJsonPath('data.status', SubscriptionPaymentStatus::Paid->value)
        ->assertJsonPath('data.amount', 25);

    $this->assertDatabaseHas('subscription_payments', [
        'subscription_member_id' => $member->id,
        'reference_month' => '2026-09',
        'status' => SubscriptionPaymentStatus::Paid->value,
    ]);

    // Update status to pending for same cycle
    $updateResponse = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$subscription->id}/members/{$member->id}/payments", [
            'reference_month' => '2026-09',
            'status' => SubscriptionPaymentStatus::Pending->value,
            'amount' => 25.00,
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.status', SubscriptionPaymentStatus::Pending->value);

    expect(SubscriptionPayment::where('subscription_member_id', $member->id)->count())->toBe(1);
});

test('cannot record payment for member belonging to another subscription', function () {
    $sub1 = Subscription::factory()->create(['workspace_id' => $this->workspace->id]);
    $sub2 = Subscription::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreignMember = SubscriptionMember::factory()->create(['subscription_id' => $sub2->id]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$sub1->id}/members/{$foreignMember->id}/payments", [
            'reference_month' => '2026-09',
            'status' => SubscriptionPaymentStatus::Paid->value,
        ]);

    $response->assertNotFound();
});

test('user can pay and unpay an individual subscription and balance is updated', function () {
    $account = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'current_balance' => 1000.00,
        'is_primary' => true,
    ]);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Spotify Individual',
        'total_amount' => 21.90,
        'bank_account_id' => $account->id,
    ]);

    // Pay subscription
    $response = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$subscription->id}/pay");

    $response->assertOk()
        ->assertJsonPath('subscription.is_paid', true);

    expect((float) $account->fresh()->current_balance)->toBe(978.10);

    // Unpay subscription
    $unpayResponse = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$subscription->id}/unpay");

    $unpayResponse->assertOk()
        ->assertJsonPath('subscription.is_paid', false);

    expect((float) $account->fresh()->current_balance)->toBe(1000.00);
});

test('marking family member payment as paid credits primary bank account and unpaying reverts balance', function () {
    $primaryAccount = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'current_balance' => 500.00,
        'is_primary' => true,
    ]);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'YouTube Premium Família',
        'total_amount' => 41.90,
    ]);

    $member = SubscriptionMember::factory()->create([
        'subscription_id' => $subscription->id,
        'name' => 'Lucas',
        'installment_amount' => 15.00,
    ]);

    $currentMonth = now()->format('Y-m');

    // Mark member as paid
    $response = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$subscription->id}/members/{$member->id}/payments", [
            'reference_month' => $currentMonth,
            'status' => 'paid',
            'amount' => 15.00,
        ]);

    $response->assertCreated();

    // Primary account should have +15.00
    expect((float) $primaryAccount->fresh()->current_balance)->toBe(515.00);

    // Mark back to pending
    $updateResponse = $this->actingAs($this->user)
        ->postJson("/api/subscriptions/{$subscription->id}/members/{$member->id}/payments", [
            'reference_month' => $currentMonth,
            'status' => 'pending',
            'amount' => 15.00,
        ]);

    $updateResponse->assertOk();

    // Primary account balance should revert to 500.00
    expect((float) $primaryAccount->fresh()->current_balance)->toBe(500.00);
});

test('subscription with credit card automatically discounts card available limit on billing day', function () {
    $card = CreditCard::factory()->create([
        'workspace_id' => $this->workspace->id,
        'total_limit' => 2000.00,
    ]);

    expect((float) $card->available_limit)->toBe(2000.00);

    $todayDay = (int) now()->day;

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Netflix 4K',
        'total_amount' => 55.90,
        'credit_card_id' => $card->id,
        'billing_day' => $todayDay,
        'is_active' => true,
    ]);

    // Listing subscriptions triggers processDueSubscriptions
    $response = $this->actingAs($this->user)
        ->getJson('/api/subscriptions');

    $response->assertOk();

    // Available limit should now be discounted by 55.90
    expect((float) $card->fresh()->available_limit)->toBe(1944.10);
});
