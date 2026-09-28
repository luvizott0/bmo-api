<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin', 'default_workspace_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'default_workspace_id' => 'integer',
        ];
    }

    /**
     * Check if the user is an administrator.
     */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Workspaces owned by the user.
     *
     * @return HasMany<Workspace, $this>
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /**
     * Workspaces the user belongs to.
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the user's primary/personal workspace.
     */
    public function personalWorkspace(): ?Workspace
    {
        return $this->ownedWorkspaces()->where('is_personal', true)->first()
            ?? $this->workspaces()->first();
    }

    /**
     * Get the user's defined default workspace.
     */
    public function defaultWorkspace(): ?Workspace
    {
        if ($this->default_workspace_id) {
            $ws = $this->workspaces()->where('workspaces.id', $this->default_workspace_id)->first();
            if ($ws) {
                return $ws;
            }
        }

        return $this->personalWorkspace();
    }

    /**
     * Resolve the current active workspace for the user.
     */
    public function currentWorkspace(?int $workspaceId = null): ?Workspace
    {
        if ($workspaceId) {
            return $this->workspaces()->where('workspaces.id', $workspaceId)->first();
        }

        return $this->defaultWorkspace();
    }
}
