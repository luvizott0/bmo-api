<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'invited_by_user_id', 'email', 'role', 'token', 'status', 'expires_at'])]
class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
