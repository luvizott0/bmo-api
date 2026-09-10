<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user and create their default personal workspace.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = DB::transaction(function () use ($data): array {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $workspace = Workspace::create([
                'owner_id' => $user->id,
                'name' => 'My Personal Workspace',
                'is_personal' => true,
            ]);

            $workspace->members()->attach($user->id, [
                'role' => WorkspaceRole::Owner->value,
            ]);

            CategorySeeder::seedForWorkspace($workspace);

            $token = $user->createToken('auth-token')->plainTextToken;

            return [
                'user' => $user,
                'workspace' => $workspace,
                'token' => $token,
            ];
        });

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => new UserResource($result['user']),
            'workspace' => new WorkspaceResource($result['workspace']),
            'token' => $result['token'],
        ], 201);
    }

    /**
     * Authenticate a user and issue an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $request->input('device_name', 'web-token');
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => new UserResource($user),
            'workspaces' => WorkspaceResource::collection($user->workspaces),
            'token' => $token,
        ]);
    }

    /**
     * Log out the authenticated user by revoking their current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get the authenticated user profile and workspaces.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $personalWorkspace = $user->personalWorkspace();

        return response()->json([
            'user' => new UserResource($user),
            'workspaces' => WorkspaceResource::collection($user->workspaces),
            'personal_workspace' => $personalWorkspace ? new WorkspaceResource($personalWorkspace) : null,
        ]);
    }

    /**
     * Update the authenticated user's password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
