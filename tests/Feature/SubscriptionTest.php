<?php

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\WorkspaceRole;
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
