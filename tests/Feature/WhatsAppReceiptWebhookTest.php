<?php

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\BankAccount;
use App\Models\Subscription;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ReceiptExtractorService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.evolution.webhook_secret', 'test_secret_123');

    $this->owner = User::factory()->create([
        'name' => 'Calebe Luvizotto',
        'email' => 'calebe@example.com',
    ]);

    $this->workspace = Workspace::factory()->create([
        'owner_id' => $this->owner->id,
        'is_personal' => true,
    ]);

    $this->workspace->members()->attach($this->owner->id, ['role' => WorkspaceRole::Owner->value]);

    $this->bankAccount = BankAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'is_primary' => true,
    ]);

    $this->subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Netflix Premium',
        'total_amount' => 60.00,
        'is_active' => true,
    ]);

    $this->member = SubscriptionMember::factory()->create([
        'subscription_id' => $this->subscription->id,
        'name' => 'João Silva',
        'contact' => '11999998888',
        'installment_amount' => 15.00,
        'is_active' => true,
    ]);
});

test('webhook rejects request without valid secret token', function () {
    $response = $this->postJson('/api/webhooks/whatsapp', [
        'event' => 'messages.upsert',
    ], [
        'X-Webhook-Token' => 'wrong_token',
    ]);

    $response->assertStatus(401);
});

test('webhook successfully processes valid pix receipt, updates payment and creates transaction', function () {
    $mockExtractor = Mockery::mock(ReceiptExtractorService::class);
    $mockExtractor->shouldReceive('extractFromBase64')
        ->once()
        ->andReturn([
            'success' => true,
            'amount' => 15.00,
            'payment_date' => '2026-09-11',
            'transaction_id' => 'E0003816620260911143000123456789',
            'payer_name' => 'João Silva',
            'recipient_name' => 'Calebe Luvizotto',
        ]);
    $this->app->instance(ReceiptExtractorService::class, $mockExtractor);

    $mockNotifier = Mockery::mock(WhatsAppNotificationService::class);
    $mockNotifier->shouldReceive('sendText')
        ->once()
        ->withArgs(function ($remoteJid, $text, $msgId) {
            return $remoteJid === '120363028374928374@g.us'
                && str_contains($text, 'Pagamento Confirmado')
                && str_contains($text, 'João Silva')
                && str_contains($text, 'Netflix Premium')
                && $msgId === 'MSG_001';
        })
        ->andReturn(true);
    $this->app->instance(WhatsAppNotificationService::class, $mockNotifier);

    $payload = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '120363028374928374@g.us',
                'fromMe' => false,
                'id' => 'MSG_001',
                'participant' => '5511999998888@s.whatsapp.net',
            ],
            'pushName' => 'João',
            'messageType' => 'imageMessage',
            'message' => [
                'imageMessage' => [
                    'mimetype' => 'image/jpeg',
                    'caption' => 'Segue o comprovante',
                ],
                'base64' => base64_encode('fake-image-bytes'),
            ],
        ],
    ];

    $response = $this->postJson('/api/webhooks/whatsapp', $payload, [
        'X-Webhook-Token' => 'test_secret_123',
    ]);

    $response->assertOk();

    // Assert SubscriptionPayment was recorded
    $payment = SubscriptionPayment::where('subscription_member_id', $this->member->id)
        ->where('reference_month', '2026-09')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe(SubscriptionPaymentStatus::Paid)
        ->and((float) $payment->amount)->toBe(15.00)
        ->and($payment->pix_e2e_id)->toBe('E0003816620260911143000123456789');

    // Assert Income Transaction was created in primary bank account
    $transaction = Transaction::where('subscription_id', $this->subscription->id)
        ->where('type', TransactionType::Income)
        ->first();

    expect($transaction)->not->toBeNull()
        ->and((float) $transaction->amount)->toBe(15.00)
        ->and($transaction->status)->toBe(TransactionStatus::Paid)
        ->and($transaction->bank_account_id)->toBe($this->bankAccount->id);
});

test('webhook detects and rejects duplicate pix receipt', function () {
    // Pre-create payment with transaction id
    SubscriptionPayment::create([
        'subscription_member_id' => $this->member->id,
        'reference_month' => '2026-08',
        'amount' => 15.00,
        'status' => 'paid',
        'pix_e2e_id' => 'E0003816620260911143000123456789',
    ]);

    $mockExtractor = Mockery::mock(ReceiptExtractorService::class);
    $mockExtractor->shouldReceive('extractFromBase64')
        ->once()
        ->andReturn([
            'success' => true,
            'amount' => 15.00,
            'payment_date' => '2026-09-11',
            'transaction_id' => 'E0003816620260911143000123456789',
            'payer_name' => 'João Silva',
            'recipient_name' => 'Calebe Luvizotto',
        ]);
    $this->app->instance(ReceiptExtractorService::class, $mockExtractor);

    $mockNotifier = Mockery::mock(WhatsAppNotificationService::class);
    $mockNotifier->shouldReceive('sendText')
        ->once()
        ->withArgs(function ($remoteJid, $text) {
            return str_contains($text, 'já foi registrado anteriormente');
        })
        ->andReturn(true);
    $this->app->instance(WhatsAppNotificationService::class, $mockNotifier);

    $payload = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '120363028374928374@g.us',
                'fromMe' => false,
                'id' => 'MSG_002',
                'participant' => '5511999998888@s.whatsapp.net',
            ],
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

    // Assert no new payment was created for 2026-09
    expect(SubscriptionPayment::where('reference_month', '2026-09')->count())->toBe(0);
});

test('webhook alerts when recipient in receipt does not match workspace owner', function () {
    $mockExtractor = Mockery::mock(ReceiptExtractorService::class);
    $mockExtractor->shouldReceive('extractFromBase64')
        ->once()
        ->andReturn([
            'success' => true,
            'amount' => 15.00,
            'payment_date' => '2026-09-11',
            'transaction_id' => 'E9999999999999999999999999999999',
            'payer_name' => 'João Silva',
            'recipient_name' => 'Fulano Totalmente Estranho',
        ]);
    $this->app->instance(ReceiptExtractorService::class, $mockExtractor);

    $mockNotifier = Mockery::mock(WhatsAppNotificationService::class);
    $mockNotifier->shouldReceive('sendText')
        ->once()
        ->withArgs(function ($remoteJid, $text) {
            return str_contains($text, 'não confere com o titular da conta');
        })
        ->andReturn(true);
    $this->app->instance(WhatsAppNotificationService::class, $mockNotifier);

    $payload = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '120363028374928374@g.us',
                'fromMe' => false,
                'id' => 'MSG_003',
                'participant' => '5511999998888@s.whatsapp.net',
            ],
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

    // Assert no payment was recorded
    expect(SubscriptionPayment::where('pix_e2e_id', 'E9999999999999999999999999999999')->exists())->toBeFalse();
});
