<?php

use App\Enums\InvitationStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can create a new collaborative workspace', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/workspaces', [
            'name' => 'Finanças da Família',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Finanças da Família')
        ->assertJsonPath('data.is_personal', false);

    $workspace = Workspace::where('name', 'Finanças da Família')->first();
    expect($workspace->members()->where('user_id', $user->id)->first()->pivot->role)->toBe(WorkspaceRole::Owner->value);
});

test('workspace owner can invite another user by email', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

    $response = $this->actingAs($owner)
        ->postJson("/api/workspaces/{$workspace->id}/invitations", [
            'email' => 'convidado@example.com',
            'role' => WorkspaceRole::Member->value,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'convidado@example.com')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('workspace_invitations', [
        'workspace_id' => $workspace->id,
        'email' => 'convidado@example.com',
        'status' => 'pending',
    ]);
});

test('invited user can accept invitation and gain access to workspace', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'convidado@example.com']);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id, 'name' => 'Casa Compartilhada']);
    $workspace->members()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by_user_id' => $owner->id,
        'email' => $invitedUser->email,
        'role' => WorkspaceRole::Member,
        'status' => InvitationStatus::Pending,
    ]);

    $response = $this->actingAs($invitedUser)
        ->postJson("/api/invitations/{$invitation->token}/accept");

    $response->assertOk()
        ->assertJsonFragment(['message' => 'Invitation accepted successfully! You are now a member of Casa Compartilhada.']);

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Accepted);
    expect($workspace->members()->where('user_id', $invitedUser->id)->exists())->toBeTrue();
});

test('invited user can reject invitation', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'convidado@example.com']);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by_user_id' => $owner->id,
        'email' => $invitedUser->email,
        'status' => InvitationStatus::Pending,
    ]);

    $response = $this->actingAs($invitedUser)
        ->postJson("/api/invitations/{$invitation->token}/reject");

    $response->assertOk()
        ->assertJsonFragment(['message' => 'Invitation rejected successfully.']);

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Rejected);
    expect($workspace->members()->where('user_id', $invitedUser->id)->exists())->toBeFalse();
});

test('user cannot access resources of a workspace they do not belong to', function () {
    $owner = User::factory()->create();
    $unrelatedUser = User::factory()->create();

    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

    $response = $this->actingAs($unrelatedUser)
        ->withHeader('X-Workspace-Id', (string) $workspace->id)
        ->getJson('/api/bank-accounts');

    $response->assertForbidden();
});
