<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreInvitationRequest;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceInvitationController extends Controller
{
    /**
     * List invitations for the specified workspace.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->ensureCanManage($request, $workspace);

        $invitations = $workspace->invitations()
            ->latest()
            ->get();

        return WorkspaceInvitationResource::collection($invitations);
    }

    /**
     * Invite another user by email to join the workspace.
     */
    public function store(StoreInvitationRequest $request, Workspace $workspace): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);

        $email = $request->input('email');
        $role = $request->input('role', WorkspaceRole::Member->value);

        // Check if user is already a member
        $alreadyMember = $workspace->members()->where('email', $email)->exists();
        if ($alreadyMember) {
            abort(422, 'This user is already a member of this workspace.');
        }

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'invited_by_user_id' => $request->user()->id,
            'email' => $email,
            'role' => $role,
            'token' => Str::random(64),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
        ]);

        return (new WorkspaceInvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show invitation info by token.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::with(['workspace', 'invitedBy'])
            ->where('token', $token)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'token' => $invitation->token,
                'workspace_name' => $invitation->workspace->name,
                'invited_by_name' => $invitation->invitedBy?->name,
                'status' => $invitation->status?->value ?? $invitation->status,
                'is_pending' => $invitation->isPending(),
            ],
        ]);
    }

    /**
     * Accept a workspace invitation using the token.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::where('token', $token)->firstOrFail();

        if (! $invitation->isPending()) {
            abort(422, 'Este convite já expirou ou foi utilizado.');
        }

        $user = $request->user();

        // Check if user is already a member
        $alreadyMember = $invitation->workspace->members()->where('user_id', $user->id)->exists();

        if (! $alreadyMember && $user->workspaces()->count() >= 3) {
            abort(422, 'Você já atingiu o limite máximo de 3 espaços.');
        }

        DB::transaction(function () use ($invitation, $user): void {
            $invitation->update([
                'status' => InvitationStatus::Accepted,
            ]);

            $invitation->workspace->members()->syncWithoutDetaching([
                $user->id => ['role' => $invitation->role->value ?? $invitation->role],
            ]);

            if (! $user->default_workspace_id) {
                $user->update(['default_workspace_id' => $invitation->workspace_id]);
            }
        });

        return response()->json([
            'message' => 'Convite aceito com sucesso! Você agora é membro do espaço '.$invitation->workspace->name.'.',
            'workspace' => new WorkspaceResource($invitation->workspace),
        ]);
    }

    /**
     * Reject a workspace invitation using the token.
     */
    public function reject(Request $request, string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::where('token', $token)->firstOrFail();

        if (! $invitation->isPending()) {
            abort(422, 'Este convite já foi concluído.');
        }

        $invitation->update([
            'status' => InvitationStatus::Rejected,
        ]);

        return response()->json([
            'message' => 'Convite recusado com sucesso.',
        ]);
    }

    private function ensureCanManage(Request $request, Workspace $workspace): void
    {
        if (! $request->user()->workspaces()->where('workspaces.id', $workspace->id)->exists()) {
            abort(403, 'Você não tem acesso a este espaço.');
        }
    }
}
