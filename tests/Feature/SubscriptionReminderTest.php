<?php

use App\Models\Friend;
use App\Models\Subscription;
use App\Models\SubscriptionMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'owner_id' => $this->user->id,
    ]);

    $this->mockNotificationService = Mockery::mock(WhatsAppNotificationService::class);
    $this->app->instance(WhatsAppNotificationService::class, $this->mockNotificationService);
});

test('does not send reminder if today is not day 9 and no force flag', function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 8, 9, 0, 0));

    $this->mockNotificationService->shouldNotReceive('sendText');

    $this->artisan('financial:send-subscription-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Hoje é dia 8');
});

test('sends humorous reminder on day 9 with shared subscriptions amounts', function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 9, 9, 0, 0));

    $friend1 = Friend::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Gabriel',
    ]);

    $friend2 = Friend::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Godoy',
    ]);

    // Shared subscription 1: Netflix (38.45 each)
    $sub1 = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Netflix Premium',
        'total_amount' => 76.90,
        'billing_day' => 10,
        'is_active' => true,
    ]);

    SubscriptionMember::factory()->create([
        'subscription_id' => $sub1->id,
        'friend_id' => $friend1->id,
        'name' => $friend1->name,
        'installment_amount' => 38.45,
        'is_active' => true,
    ]);

    // Shared subscription 2: Spotify (6.82 each)
    $sub2 = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Spotify Family',
        'total_amount' => 40.90,
        'billing_day' => 10,
        'is_active' => true,
    ]);

    SubscriptionMember::factory()->create([
        'subscription_id' => $sub2->id,
        'friend_id' => $friend2->id,
        'name' => $friend2->name,
        'installment_amount' => 6.82,
        'is_active' => true,
    ]);

    // Individual subscription: should not appear in shared list
    Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'Mercado Livre',
        'total_amount' => 149.00,
        'billing_day' => 10,
        'is_active' => true,
    ]);

    config()->set('services.evolution.subscriptions_reminder_jid', '120363000@g.us');

    $this->mockNotificationService->shouldReceive('sendText')
        ->once()
        ->withArgs(function ($jid, $text) {
            expect($jid)->toBe('120363000@g.us');
            expect($text)->toContain('Xerife BMO passando aqui para relembrar vocês que o pagamento das assinaturas é amanhã');
            expect($text)->toContain('• *Netflix Premium:* R$ 38,45 cada');
            expect($text)->toContain('• *Spotify Family:* R$ 6,82 cada');
            expect($text)->not->toContain('Mercado Livre');

            return true;
        })
        ->andReturn(true);

    $this->artisan('financial:send-subscription-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('Lembrete enviado com sucesso!');
});

test('sends reminder with --force flag on any day and accepts --jid option', function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 15, 14, 0, 0));

    $sub = Subscription::factory()->create([
        'workspace_id' => $this->workspace->id,
        'service_name' => 'YouTube Premium',
        'total_amount' => 53.90,
        'is_active' => true,
    ]);

    SubscriptionMember::factory()->create([
        'subscription_id' => $sub->id,
        'name' => 'Gabs',
        'installment_amount' => 8.98,
        'is_active' => true,
    ]);

    $this->mockNotificationService->shouldReceive('sendText')
        ->once()
        ->withArgs(function ($jid, $text) {
            expect($jid)->toBe('custom_group@g.us');
            expect($text)->toContain('• *YouTube Premium:* R$ 8,98 cada');

            return true;
        })
        ->andReturn(true);

    $this->artisan('financial:send-subscription-reminders', [
        '--force' => true,
        '--jid' => 'custom_group@g.us',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Lembrete enviado com sucesso!');
});
