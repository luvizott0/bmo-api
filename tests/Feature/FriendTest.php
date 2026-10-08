<?php

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\Friend;
use App\Models\Subscription;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ReceiptExtractorService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.evolution.webhook_secret', 'test_secret_123');

    $this->user = User::factory()->create([
        'name' => 'Calebe Luvizotto',
        'email' => 'calebe@example.com',
    ]);

    $this->workspace = Workspace::factory()->create([
        'owner_id' => $this->user->id,
        'is_personal' => true,
    ]);

    $this->workspace->members()->attach($this->user->id, ['role' => WorkspaceRole::Owner->value]);
});

test('user can list, create, update and delete friends', function () {
    $this->actingAs($this->user);

    // Create friend
    $storeResponse = $this->postJson('/api/friends', [
        'name' => 'Gabriel Medina',
        'phone' => '11988887777',
        'notes' => 'Amigo do surfe',
    ], [
        'X-Workspace-Id' => (string) $this->workspace->id,
    ]);

    $storeResponse->assertCreated()
        ->assertJsonPath('data.name', 'Gabriel Medina')
        ->assertJsonPath('data.phone', '11988887777');

    $friendId = $storeResponse->json('data.id');

    // List friends
    $listResponse = $this->getJson('/api/friends', [
        'X-Workspace-Id' => (string) $this->workspace->id,
    ]);

    $listResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $friendId);

    // Update friend
    $updateResponse = $this->putJson("/api/friends/{$friendId}", [
        'name' => 'Gabriel Medina Silva',
        'phone' => '11999991111',
    ], [
        'X-Workspace-Id' => (string) $this->workspace->id,
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.name', 'Gabriel Medina Silva')
        ->assertJsonPath('data.phone', '11999991111');

    // Delete friend
    $deleteResponse = $this->deleteJson("/api/friends/{$friendId}", [], [
        'X-Workspace-Id' => (string) $this->workspace->id,
    ]);

    $deleteResponse->assertOk();
    expect(Friend::count())->toBe(0);
});

test('friend can be linked to subscription member and autofills data', function () {
    $this->actingAs($this->user);

    $friend = Friend::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Matheus Costa',
        'phone' => '11977776666',
    ]);

    $subResponse = $this->postJson('/api/subscriptions', [
        'service_name' => 'Spotify Família',
        'total_amount' => 40.00,
        'billing_day' => 15,
        'members' => [
            [
                'friend_id' => $friend->id,
                'name' => '', // should autofill
                'installment_amount' => 10.00,
            ],
        ],
    ], [
        'X-Workspace-Id' => (string) $this->workspace->id,
    ]);

    $subResponse->assertCreated()
        ->assertJsonPath('data.members.0.friend_id', $friend->id)
        ->assertJsonPath('data.members.0.name', 'Matheus Costa')
        ->assertJsonPath('data.members.0.contact', '11977776666');
});

test('whatsapp webhook matches member via registered friend phone and settles subscription', function () {
    $bankAccount = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'is_primary' => true,
    ]);

    $friend = Friend::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Lucas Lima',
        'phone' => '(11) 98765-4321', // formatted phone
    ]);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Max HBO',
        'total_amount' => 30.00,
        'is_active' => true,
    ]);

    $member = SubscriptionMember::factory()->create([
        'subscription_id' => $subscription->id,
        'friend_id' => $friend->id,
        'name' => 'Lucas Lima',
        'contact' => '11987654321',
        'installment_amount' => 15.00,
        'is_active' => true,
    ]);

    $mockExtractor = Mockery::mock(ReceiptExtractorService::class);
    $mockExtractor->shouldReceive('extractFromBase64')
        ->once()
        ->andReturn([
            'success' => true,
            'amount' => 15.00,
            'payment_date' => '2026-10-08',
            'transaction_id' => 'E_PIX_FRIEND_MATCH_123',
            'payer_name' => 'CONTA DA MAE DELE', // different payer name
            'recipient_name' => 'Calebe Luvizotto',
        ]);
    $this->app->instance(ReceiptExtractorService::class, $mockExtractor);

    $mockNotifier = Mockery::mock(WhatsAppNotificationService::class);
    $mockNotifier->shouldReceive('sendText')
        ->once()
        ->withArgs(function ($remoteJid, $text, $msgId) {
            return str_contains($text, 'Pagamento Confirmado')
                && str_contains($text, 'Lucas Lima')
                && str_contains($text, 'Max HBO');
        })
        ->andReturn(true);
    $this->app->instance(WhatsAppNotificationService::class, $mockNotifier);

    // WhatsApp incoming payload with participant phone
    $payload = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511987654321@s.whatsapp.net',
                'fromMe' => false,
                'id' => 'MSG_FRIEND_01',
                'participant' => '5511987654321@s.whatsapp.net',
            ],
            'pushName' => 'Lucas',
            'messageType' => 'imageMessage',
            'message' => [
                'imageMessage' => ['mimetype' => 'image/jpeg'],
                'base64' => base64_encode('fake-image-bytes'),
            ],
        ],
    ];

    $response = $this->postJson('/api/webhooks/whatsapp', $payload, [
        'X-Webhook-Token' => 'test_secret_123',
    ]);

    $response->assertOk();

    // Verify payment was recorded for Lucas Lima
    $payment = SubscriptionPayment::where('subscription_member_id', $member->id)
        ->where('reference_month', '2026-10')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe(SubscriptionPaymentStatus::Paid)
        ->and((float) $payment->amount)->toBe(15.00)
        ->and($payment->pix_e2e_id)->toBe('E_PIX_FRIEND_MATCH_123');
});

test('user can fetch subscriptions payment history', function () {
    $this->actingAs($this->user);

    $subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'YouTube Premium',
        'total_amount' => 41.90,
    ]);

    $member = SubscriptionMember::factory()->create([
        'subscription_id' => $subscription->id,
        'name' => 'Arthur',
        'installment_amount' => 10.00,
    ]);

    SubscriptionPayment::create([
        'subscription_member_id' => $member->id,
        'reference_month' => '2026-09',
        'amount' => 10.00,
        'status' => 'paid',
        'payment_date' => '2026-09-10',
    ]);

    $response = $this->getJson('/api/subscriptions/history', [
        'X-Workspace-Id' => (string) $this->workspace->id,
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subscription_name', 'YouTube Premium')
        ->assertJsonPath('data.0.member_name', 'Arthur')
        ->assertJsonPath('data.0.amount', 10)
        ->assertJsonPath('total_paid', 10);
});
