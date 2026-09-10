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
     * Accept a workspace invitation using the token.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::where('token', $token)->firstOrFail();

        if (! $invitation->isPending()) {
            abort(422, 'This invitation has expired or has already been used.');
        }

        $user = $request->user();

        DB::transaction(function () use ($invitation, $user): void {
            $invitation->update([
                'status' => InvitationStatus::Accepted,
            ]);

            $invitation->workspace->members()->syncWithoutDetaching([
                $user->id => ['role' => $invitation->role->value ?? $invitation->role],
            ]);
        });

        return response()->json([
            'message' => 'Invitation accepted successfully! You are now a member of '.$invitation->workspace->name.'.',
        ]);
    }

    /**
     * Reject a workspace invitation using the token.
     */
    public function reject(Request $request, string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::where('token', $token)->firstOrFail();

        if (! $invitation->isPending()) {
            abort(422, 'This invitation has already been concluded.');
        }

        $invitation->update([
            'status' => InvitationStatus::Rejected,
        ]);

        return response()->json([
            'message' => 'Invitation rejected successfully.',
        ]);
    }

    private function ensureCanManage(Request $request, Workspace $workspace): void
    {
        $member = $workspace->members()->where('user_id', $request->user()->id)->first();

        if (! $member || ! in_array($member->pivot->role, [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value], true)) {
            abort(403, 'Only owners and administrators can send invitations for this workspace.');
        }
    }
}
