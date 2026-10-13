<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmailInvoiceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->user->workspaces()->attach($this->workspace->id, ['role' => 'owner']);
});

it('syncs CPFL and Alares invoices from email, updates current month bill amount, and notifies the financeiro WhatsApp group', function () {
    $luzBill = FixedBill::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Conta de Luz (CPFL)',
        'type' => TransactionType::Expense,
        'estimated_amount' => 150.00,
        'due_day' => 15,
        'is_active' => true,
    ]);

    $internetBill = FixedBill::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Internet Alares',
        'type' => TransactionType::Expense,
        'estimated_amount' => 99.90,
        'due_day' => 20,
        'is_active' => true,
    ]);

    $currentMonth = now()->format('Y-m');
    $cpflDueDate = "{$currentMonth}-15";
    $alaresDueDate = "{$currentMonth}-20";

    Http::fake([
        '*/fetch-imap-invoices' => Http::response([
            'success' => true,
            'scanned' => 25,
            'invoices' => [
                [
                    'success' => true,
                    'provider' => 'CPFL',
                    'bill_type' => 'luz',
                    'amount' => 214.75,
                    'due_date' => $cpflDueDate,
                    'reference_month' => $currentMonth,
                    'pix_code' => '00020101021226850014br.gov.bcb.pix2563pix.cpfl.com.br/qr/v2/cpfl1235204000053039865802BR5904CPFL6008CAMPINAS62070503***6304ABCD',
                    'barcode' => '83620000002-1 14750162010-2 00123456789-3 00123456789-4',
                    'external_message_id' => '<cpfl-oct-2026@cpfl.com.br>',
                ],
                [
                    'success' => true,
                    'provider' => 'Alares',
                    'bill_type' => 'internet',
                    'amount' => 109.90,
                    'due_date' => $alaresDueDate,
                    'reference_month' => $currentMonth,
                    'pix_code' => '00020101021226850014br.gov.bcb.pix2563pix.alares.com.br/qr/v2/alares4565204000053039865802BR5906ALARES6009SAO PAULO62070503***6304EF01',
                    'barcode' => null,
                    'external_message_id' => '<alares-oct-2026@alares.com.br>',
                ],
            ],
        ]),
        '*/message/sendText/*' => Http::response(['status' => 'SENT'], 200),
    ]);

    $syncService = app(EmailInvoiceSyncService::class);
    $result = $syncService->syncFromEmail(notifyWhatsApp: true);

    expect($result['success'])->toBeTrue()
        ->and($result['synced'])->toBe(2);

    // Base estimated_amount should remain untouched for future months
    expect((float) $luzBill->fresh()->estimated_amount)->toBe(150.00);

    // Pending transaction created for this month with real invoice amount + pix_code + barcode
    $luzTx = Transaction::where('fixed_bill_id', $luzBill->id)->first();
    expect($luzTx)->not->toBeNull()
        ->and((float) $luzTx->amount)->toBe(214.75)
        ->and($luzTx->status)->toBe(TransactionStatus::Pending)
        ->and($luzTx->pix_code)->toContain('br.gov.bcb.pix')
        ->and($luzTx->barcode)->toContain('83620000002');

    // Verify API response for fixed-bills exposes current_month_amount and current_invoice
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Workspace-Id' => $this->workspace->id])
        ->getJson('/api/fixed-bills');

    $response->assertOk();
    $billsData = collect($response->json('data'));
    $luzJson = $billsData->firstWhere('id', $luzBill->id);

    expect((float) $luzJson['current_month_amount'])->toBe(214.75)
        ->and($luzJson['is_paid'])->toBeFalse()
        ->and($luzJson['current_invoice']['pix_code'])->toContain('br.gov.bcb.pix')
        ->and($luzJson['current_invoice']['barcode'])->toContain('83620000002');
});

it('responds to "me mostre as contas a pagar" command on WhatsApp with pending bills and Pix codes', function () {
    $luzBill = FixedBill::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Luz (CPFL)',
        'type' => TransactionType::Expense,
        'estimated_amount' => 150.00,
        'due_day' => 15,
        'is_active' => true,
    ]);

    Transaction::create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'fixed_bill_id' => $luzBill->id,
        'type' => TransactionType::Expense,
        'status' => TransactionStatus::Pending,
        'description' => 'Fatura Luz',
        'amount' => 198.40,
        'occurred_at' => now()->format('Y-m-15'),
        'pix_code' => '00020101021226850014br.gov.bcb.pix2563cpfl63041234',
    ]);

    $sentMessages = [];
    Http::fake(function ($request) use (&$sentMessages) {
        if (str_contains($request->url(), '/message/sendText/')) {
            $sentMessages[] = $request->data();

            return Http::response(['status' => 'SENT'], 200);
        }

        return Http::response([], 200);
    });

    $webhookSecret = config('services.evolution.webhook_secret');

    $payload = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '120363414319267741@g.us',
                'fromMe' => false,
                'id' => 'MSG_CMD_1',
            ],
            'pushName' => 'Luviz',
            'messageType' => 'conversation',
            'message' => [
                'conversation' => 'me mostre as contas a pagar',
            ],
        ],
    ];

    $this->postJson("/api/webhooks/whatsapp?token={$webhookSecret}", $payload)
        ->assertOk();

    expect($sentMessages)->not->toBeEmpty();
    $combinedText = collect($sentMessages)->pluck('text')->implode("\n");

    expect($combinedText)->toContain('Luz (CPFL)')
        ->and($combinedText)->toContain('198,40')
        ->and($combinedText)->toContain('00020101021226850014br.gov.bcb.pix2563cpfl63041234');
});

it('marks a pending FixedBill as paid when a receipt is sent in the financeiro WhatsApp group', function () {
    $luzBill = FixedBill::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Luz (CPFL)',
        'type' => TransactionType::Expense,
        'estimated_amount' => 150.00,
        'due_day' => 15,
        'is_active' => true,
    ]);

    $pendingTx = Transaction::create([
        'workspace_id' => $this->workspace->id,
        'created_by_user_id' => $this->user->id,
        'fixed_bill_id' => $luzBill->id,
        'type' => TransactionType::Expense,
        'status' => TransactionStatus::Pending,
        'description' => 'Fatura Luz',
        'amount' => 198.40,
        'occurred_at' => now()->format('Y-m-15'),
    ]);

    Http::fake([
        '*/extract' => Http::response([
            'success' => true,
            'amount' => 198.40,
            'payment_date' => now()->toDateString(),
            'transaction_id' => 'E123456789CPFL',
            'payer_name' => 'Luviz',
            'recipient_name' => 'CPFL Paulista',
        ], 200),
        '*/message/sendText/*' => Http::response(['status' => 'SENT'], 200),
    ]);

    $webhookSecret = config('services.evolution.webhook_secret');

    $payload = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '120363414319267741@g.us',
                'fromMe' => false,
                'id' => 'MSG_PAY_CPFL_1',
            ],
            'pushName' => 'Luviz',
            'messageType' => 'imageMessage',
            'message' => [
                'base64' => base64_encode('fake-image-bytes'),
                'imageMessage' => [
                    'mimetype' => 'image/jpeg',
                    'caption' => 'comprovante luz',
                ],
            ],
        ],
    ];

    $this->postJson("/api/webhooks/whatsapp?token={$webhookSecret}", $payload)
        ->assertOk();

    expect($pendingTx->fresh()->status)->toBe(TransactionStatus::Paid);
});
