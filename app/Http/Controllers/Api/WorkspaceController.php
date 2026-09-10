<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreWorkspaceRequest;
use App\Http\Requests\Api\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    /**
     * Display a listing of workspaces the user belongs to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspaces = $request->user()->workspaces()->get();

        return WorkspaceResource::collection($workspaces);
    }

    /**
     * Store a newly created workspace.
     */
    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $user = $request->user();

        $workspace = DB::transaction(function () use ($user, $request): Workspace {
            $workspace = Workspace::create([
                'owner_id' => $user->id,
                'name' => $request->input('name'),
                'is_personal' => false,
            ]);

            $workspace->members()->attach($user->id, [
                'role' => WorkspaceRole::Owner->value,
            ]);

            CategorySeeder::seedForWorkspace($workspace);

            return $workspace;
        });

        return (new WorkspaceResource($workspace))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified workspace.
     */
    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->ensureMember($request, $workspace);

        return (new WorkspaceResource($workspace))->response();
    }

    /**
     * Update the specified workspace.
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        $this->ensureCanManage($request, $workspace);

        $workspace->update($request->validated());

        return (new WorkspaceResource($workspace))->response();
    }

    private function ensureMember(Request $request, Workspace $workspace): void
    {
        if (! $request->user()->workspaces()->where('workspaces.id', $workspace->id)->exists()) {
            abort(403, 'You do not have access to this workspace.');
        }
    }

    private function ensureCanManage(Request $request, Workspace $workspace): void
    {
        $member = $workspace->members()->where('user_id', $request->user()->id)->first();

        if (! $member || ! in_array($member->pivot->role, [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value], true)) {
            abort(403, 'Only owners and administrators can manage this workspace.');
        }
    }
}
