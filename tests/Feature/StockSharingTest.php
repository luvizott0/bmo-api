<?php

use App\Enums\WorkspaceRole;
use App\Models\InventoryItem;
use App\Models\StockShareInvitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->userA = User::factory()->create(['name' => 'Alice']);
    $this->workspaceA = Workspace::factory()->create([
        'owner_id' => $this->userA->id,
        'name' => 'Casa da Alice',
        'is_personal' => true,
    ]);
    $this->workspaceA->members()->attach($this->userA->id, ['role' => WorkspaceRole::Owner->value]);

    $this->userB = User::factory()->create(['name' => 'Bob']);
    $this->workspaceB = Workspace::factory()->create([
        'owner_id' => $this->userB->id,
        'name' => 'Casa do Bob',
        'is_personal' => true,
    ]);
    $this->workspaceB->members()->attach($this->userB->id, ['role' => WorkspaceRole::Owner->value]);
});

test('user starts with individual unshared stock status', function () {
    $response = $this->actingAs($this->userA)
        ->getJson('/api/stock-shares/status');

    $response->assertOk()
        ->assertJsonPath('is_shared', false)
        ->assertJsonPath('is_owner', true)
        ->assertJsonPath('pending_invitation', null)
        ->assertJsonCount(1, 'members')
        ->assertJsonPath('members.0.name', 'Alice');
});

test('user can generate a single-use stock share invitation', function () {
    $response = $this->actingAs($this->userA)
        ->postJson('/api/stock-shares/invite');

    $response->assertCreated()
        ->assertJsonStructure(['message', 'token', 'expires_at']);

    $token = $response->json('token');

    $this->assertDatabaseHas('stock_share_invitations', [
        'workspace_id' => $this->workspaceA->id,
        'created_by_user_id' => $this->userA->id,
        'token' => $token,
        'status' => 'pending',
    ]);

    // Status now reports pending invitation
    $statusResponse = $this->actingAs($this->userA)
        ->getJson('/api/stock-shares/status');

    $statusResponse->assertOk()
        ->assertJsonPath('pending_invitation.token', $token);
});

test('generating a new invite link revokes previously pending ones', function () {
    $firstResponse = $this->actingAs($this->userA)
        ->postJson('/api/stock-shares/invite');
    $firstToken = $firstResponse->json('token');

    $secondResponse = $this->actingAs($this->userA)
        ->postJson('/api/stock-shares/invite');
    $secondToken = $secondResponse->json('token');

    expect($firstToken)->not->toBe($secondToken);

    $this->assertDatabaseHas('stock_share_invitations', [
        'token' => $firstToken,
        'status' => 'revoked',
    ]);

    $this->assertDatabaseHas('stock_share_invitations', [
        'token' => $secondToken,
        'status' => 'pending',
    ]);
});

test('user can revoke a pending invite', function () {
    $inviteResponse = $this->actingAs($this->userA)
        ->postJson('/api/stock-shares/invite');
    $token = $inviteResponse->json('token');

    $revokeResponse = $this->actingAs($this->userA)
        ->deleteJson('/api/stock-shares/invite');

    $revokeResponse->assertOk()
        ->assertJsonPath('message', 'Convite revogado com sucesso.');

    $this->assertDatabaseHas('stock_share_invitations', [
        'token' => $token,
        'status' => 'revoked',
    ]);
});

test('user can preview stock invite details before accepting', function () {
    // Add an item to Alice's stock
    InventoryItem::factory()->create([
        'workspace_id' => $this->workspaceA->id,
        'name' => 'Café Torrado',
    ]);

    $invite = StockShareInvitation::create([
        'workspace_id' => $this->workspaceA->id,
        'created_by_user_id' => $this->userA->id,
        'token' => 'sample-test-token-123',
        'status' => 'pending',
        'expires_at' => now()->addHours(48),
    ]);

    $response = $this->actingAs($this->userB)
        ->getJson('/api/stock-shares/invitations/sample-test-token-123');

    $response->assertOk()
        ->assertJsonPath('is_valid', true)
        ->assertJsonPath('inviter_name', 'Alice')
        ->assertJsonPath('workspace_name', 'Casa da Alice')
        ->assertJsonPath('items_count', 1);
});

test('recipient can accept single-use invitation and both share the same stock', function () {
    // Alice has Coffee
    $itemA = InventoryItem::factory()->create([
        'workspace_id' => $this->workspaceA->id,
        'name' => 'Café em Grãos',
        'quantity' => 2,
    ]);

    // Bob has Milk
    $itemB = InventoryItem::factory()->create([
        'workspace_id' => $this->workspaceB->id,
        'name' => 'Leite Integral',
        'quantity' => 4,
    ]);

    $invite = StockShareInvitation::create([
        'workspace_id' => $this->workspaceA->id,
        'created_by_user_id' => $this->userA->id,
        'token' => 'single-use-token-xyz',
        'status' => 'pending',
        'expires_at' => now()->addHours(48),
    ]);

    // Bob accepts Alice's invite
    $acceptResponse = $this->actingAs($this->userB)
        ->postJson('/api/stock-shares/invitations/single-use-token-xyz/accept');

    $acceptResponse->assertOk()
        ->assertJsonFragment(['message' => 'Estoque compartilhado conectado com sucesso! A partir de agora vocês gerenciam o mesmo estoque.']);

    // Check invitation is marked accepted and used by Bob
    expect($invite->fresh()->status)->toBe('accepted');
    expect($invite->fresh()->used_by_user_id)->toBe($this->userB->id);
    expect($this->workspaceB->fresh()->stock_workspace_id)->toBe($this->workspaceA->id);

    // Both items now belong to the shared workspace (Bob's milk was merged!)
    expect($itemB->fresh()->workspace_id)->toBe($this->workspaceA->id);

    // When Alice lists inventory items, she sees both Coffee and Milk
    $aliceItems = $this->actingAs($this->userA)->getJson('/api/inventory-items');
    $aliceItems->assertOk()->assertJsonCount(2, 'data');

    // When Bob lists inventory items, he sees both Coffee and Milk
    $bobItems = $this->actingAs($this->userB)->getJson('/api/inventory-items');
    $bobItems->assertOk()->assertJsonCount(2, 'data');

    // Bob consumes 1 coffee
    $this->actingAs($this->userB)->postJson("/api/inventory-items/{$itemA->id}/consume", ['quantity' => 1])
        ->assertOk();

    // Alice sees coffee quantity decreased to 1
    expect((float) $itemA->fresh()->quantity)->toBe(1.0);
});

test('invite token cannot be reused once accepted (single-use)', function () {
    $invite = StockShareInvitation::create([
        'workspace_id' => $this->workspaceA->id,
        'created_by_user_id' => $this->userA->id,
        'token' => 're-use-test-token',
        'status' => 'pending',
        'expires_at' => now()->addHours(48),
    ]);

    // First use succeeds
    $this->actingAs($this->userB)
        ->postJson('/api/stock-shares/invitations/re-use-test-token/accept')
        ->assertOk();

    // Second user attempts to use same token
    $userC = User::factory()->create();
    $workspaceC = Workspace::factory()->create(['owner_id' => $userC->id]);
    $workspaceC->members()->attach($userC->id, ['role' => WorkspaceRole::Owner->value]);

    $secondAttempt = $this->actingAs($userC)
        ->postJson('/api/stock-shares/invitations/re-use-test-token/accept');

    $secondAttempt->assertStatus(422)
        ->assertJsonFragment(['message' => 'Este convite de estoque é de uso único e já foi utilizado ou expirou.']);
});

test('user cannot accept their own stock invitation', function () {
    $invite = StockShareInvitation::create([
        'workspace_id' => $this->workspaceA->id,
        'created_by_user_id' => $this->userA->id,
        'token' => 'self-invite-token',
        'status' => 'pending',
        'expires_at' => now()->addHours(48),
    ]);

    $response = $this->actingAs($this->userA)
        ->postJson('/api/stock-shares/invitations/self-invite-token/accept');

    $response->assertStatus(422);
});

test('user can leave shared stock and return to individual stock', function () {
    // Connect Bob's workspace to Alice's
    $this->workspaceB->update(['stock_workspace_id' => $this->workspaceA->id]);

    $response = $this->actingAs($this->userB)
        ->postJson('/api/stock-shares/leave');

    $response->assertOk()
        ->assertJsonFragment(['message' => 'Você se desconectou do estoque compartilhado e voltou a ter um estoque individual.']);

    expect($this->workspaceB->fresh()->stock_workspace_id)->toBeNull();
});
