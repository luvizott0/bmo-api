<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\StockCategory;
use App\Models\StockShareInvitation;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockShareController extends Controller
{
    /**
     * Get the current stock sharing status for the active workspace.
     */
    public function status(Request $request): JsonResponse
    {
        $workspace = $request->workspace();
        $effectiveWorkspace = $workspace->effectiveStockWorkspace();

        $connectedWorkspaces = Workspace::with('owner')
            ->where('stock_workspace_id', $effectiveWorkspace->id)
            ->get();

        $isShared = $workspace->stock_workspace_id !== null || $connectedWorkspaces->isNotEmpty();
        $isOwner = $workspace->stock_workspace_id === null;

        $members = collect();

        // Add the owner of the effective workspace
        if ($effectiveWorkspace->owner) {
            $members->push([
                'id' => $effectiveWorkspace->owner->id,
                'name' => $effectiveWorkspace->owner->name,
                'email' => $effectiveWorkspace->owner->email,
                'is_owner' => true,
            ]);
        }

        // Add other connected members
        foreach ($connectedWorkspaces as $cw) {
            if ($cw->owner && $cw->owner->id !== $effectiveWorkspace->owner_id) {
                $members->push([
                    'id' => $cw->owner->id,
                    'name' => $cw->owner->name,
                    'email' => $cw->owner->email,
                    'is_owner' => false,
                ]);
            }
        }

        // Check for active pending single-use invitation
        $pendingInvite = StockShareInvitation::where('workspace_id', $effectiveWorkspace->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        $inviteData = null;
        if ($pendingInvite) {
            $inviteData = [
                'token' => $pendingInvite->token,
                'expires_at' => $pendingInvite->expires_at->toIso8601String(),
                'created_at' => $pendingInvite->created_at->toIso8601String(),
            ];
        }

        return response()->json([
            'is_shared' => $isShared,
            'is_owner' => $isOwner,
            'owner' => [
                'id' => $effectiveWorkspace->owner?->id,
                'name' => $effectiveWorkspace->owner?->name,
                'email' => $effectiveWorkspace->owner?->email,
            ],
            'members' => $members->values(),
            'pending_invitation' => $inviteData,
            'items_count' => $effectiveWorkspace->inventoryItems()->count(),
        ]);
    }

    /**
     * Generate a new single-use invite link for the stock.
     */
    public function createInvite(Request $request): JsonResponse
    {
        $workspace = $request->workspace();
        $effectiveWorkspace = $workspace->effectiveStockWorkspace();

        // Invalidate any previous pending invitations for this workspace
        StockShareInvitation::where('workspace_id', $effectiveWorkspace->id)
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        $token = Str::random(64);
        $expiresAt = now()->addHours(48);

        $invitation = StockShareInvitation::create([
            'workspace_id' => $effectiveWorkspace->id,
            'created_by_user_id' => $request->user()->id,
            'token' => $token,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'message' => 'Link de compartilhamento de estoque gerado com sucesso!',
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }

    /**
     * Revoke any pending invitation for the stock.
     */
    public function revokeInvite(Request $request): JsonResponse
    {
        $workspace = $request->workspace();
        $effectiveWorkspace = $workspace->effectiveStockWorkspace();

        StockShareInvitation::where('workspace_id', $effectiveWorkspace->id)
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        return response()->json([
            'message' => 'Convite revogado com sucesso.',
        ]);
    }

    /**
     * Preview invitation details before accepting.
     */
    public function showInvite(Request $request, string $token): JsonResponse
    {
        $invitation = StockShareInvitation::with(['workspace.owner', 'createdByUser'])
            ->where('token', $token)
            ->first();

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json([
                'is_valid' => false,
                'message' => 'Este convite de estoque expirou ou já foi utilizado.',
            ], 404);
        }

        return response()->json([
            'is_valid' => true,
            'inviter_name' => $invitation->createdByUser?->name ?? $invitation->workspace->owner?->name ?? 'Usuário',
            'workspace_name' => $invitation->workspace->name,
            'items_count' => $invitation->workspace->inventoryItems()->count(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Accept a single-use stock share invitation.
     */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        $workspace = $request->workspace();

        $invitation = StockShareInvitation::with('workspace')
            ->where('token', $token)
            ->first();

        if (! $invitation || ! $invitation->isPending()) {
            abort(422, 'Este convite de estoque é de uso único e já foi utilizado ou expirou.');
        }

        // Prevent accepting own invite or already shared with same stock
        $targetWorkspaceId = $invitation->workspace_id;
        if ($workspace->id === $targetWorkspaceId || $workspace->stock_workspace_id === $targetWorkspaceId) {
            abort(422, 'Você já faz parte deste estoque compartilhado.');
        }

        DB::transaction(function () use ($invitation, $workspace, $targetWorkspaceId, $user): void {
            // Mark invitation as accepted (single-use)
            $invitation->markAsAccepted($user);

            // Merge any existing items, categories, and purchases from recipient's workspace into target workspace
            StockCategory::where('workspace_id', $workspace->id)
                ->update(['workspace_id' => $targetWorkspaceId]);

            InventoryItem::where('workspace_id', $workspace->id)
                ->update(['workspace_id' => $targetWorkspaceId]);

            InventoryPurchase::where('workspace_id', $workspace->id)
                ->update(['workspace_id' => $targetWorkspaceId]);

            // Connect recipient workspace to target stock workspace
            $workspace->update([
                'stock_workspace_id' => $targetWorkspaceId,
            ]);
        });

        return response()->json([
            'message' => 'Estoque compartilhado conectado com sucesso! A partir de agora vocês gerenciam o mesmo estoque.',
        ]);
    }

    /**
     * Disconnect/leave shared stock.
     */
    public function leave(Request $request): JsonResponse
    {
        $workspace = $request->workspace();

        // If member disconnecting from shared stock
        if ($workspace->stock_workspace_id !== null) {
            $workspace->update(['stock_workspace_id' => null]);

            return response()->json([
                'message' => 'Você se desconectou do estoque compartilhado e voltou a ter um estoque individual.',
            ]);
        }

        // If owner removing a specific member
        $memberUserId = $request->input('member_user_id');
        if ($memberUserId) {
            $memberWorkspace = Workspace::where('owner_id', $memberUserId)
                ->where('stock_workspace_id', $workspace->id)
                ->first();

            if ($memberWorkspace) {
                $memberWorkspace->update(['stock_workspace_id' => null]);

                return response()->json([
                    'message' => 'Membro desconectado do estoque compartilhado com sucesso.',
                ]);
            }
        }

        return response()->json([
            'message' => 'Nenhuma alteração necessária.',
        ]);
    }
}
