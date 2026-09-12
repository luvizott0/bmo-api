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

test('webhook fetches media from API and matches member with diminutive nickname when forwarded', function () {
    // Create member Oscarzinho
    $oscar = SubscriptionMember::factory()->create([
        'subscription_id' => $this->subscription->id,
        'name' => 'Oscarzinho',
        'contact' => '11911112222',
        'installment_amount' => 8.98,
        'is_active' => true,
    ]);

    $mockNotifier = Mockery::mock(WhatsAppNotificationService::class);
    // Should fetch base64 from evolution API
    $mockNotifier->shouldReceive('getBase64FromMediaMessage')
        ->once()
        ->with('MSG_FORWARDED_001')
        ->andReturn([
            'base64' => base64_encode('fake-pdf-content'),
            'mimetype' => 'application/pdf',
            'fileName' => 'comprovante.pdf',
        ]);

    // Should send confirmation matching Oscarzinho
    $mockNotifier->shouldReceive('sendText')
        ->once()
        ->withArgs(function ($remoteJid, $text, $msgId) {
            return $remoteJid === '5511988887777@s.whatsapp.net'
                && str_contains($text, 'Pagamento Confirmado')
                && str_contains($text, 'Oscarzinho')
                && str_contains($text, '8,98')
                && $msgId === 'MSG_FORWARDED_001';
        })
        ->andReturn(true);

    $this->app->instance(WhatsAppNotificationService::class, $mockNotifier);

    $mockExtractor = Mockery::mock(ReceiptExtractorService::class);
    $mockExtractor->shouldReceive('extractFromBase64')
        ->once()
        ->andReturn([
            'success' => true,
            'amount' => 8.98,
            'payment_date' => '2026-09-07',
            'transaction_id' => 'E2289643120260907210217038166666',
            'payer_name' => 'OSCAR BOBERG FILHO',
            'recipient_name' => 'Calebe Luvizotto',
        ]);
    $this->app->instance(ReceiptExtractorService::class, $mockExtractor);

    // Payload WITHOUT inline base64, forwarded by someone else (e.g. Calebe)
    $payload = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511988887777@s.whatsapp.net',
                'fromMe' => false,
                'id' => 'MSG_FORWARDED_001',
            ],
            'pushName' => 'Calebe',
            'messageType' => 'documentMessage',
            'message' => [
                'documentMessage' => [
                    'mimetype' => 'application/pdf',
                    'fileName' => 'comprovante.pdf',
                ],
                // Notice: no 'base64' key here!
            ],
        ],
    ];

    $response = $this->postJson('/api/webhooks/whatsapp', $payload, [
        'X-Webhook-Token' => 'test_secret_123',
    ]);

    $response->assertOk();

    // Assert payment was recorded for Oscarzinho and cycle 2026-09
    $payment = SubscriptionPayment::where('subscription_member_id', $oscar->id)
        ->where('reference_month', '2026-09')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe(SubscriptionPaymentStatus::Paid)
        ->and((float) $payment->amount)->toBe(8.98)
        ->and($payment->pix_e2e_id)->toBe('E2289643120260907210217038166666');
});
