<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->safeEmail(),
            'role' => WorkspaceRole::Member,
            'token' => Str::random(40),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
        ];
    }
}
