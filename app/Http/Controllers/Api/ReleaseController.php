<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReleaseResource;
use App\Models\Project;
use App\Models\Release;
use App\Models\Task;
use App\Services\AiTaskDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReleaseController extends Controller
{
    public function __construct(
        private readonly AiTaskDispatcherService $aiDispatcher
    ) {}

    /**
     * Display a listing of releases for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->workspace()
            ->releases()
            ->with(['project', 'tasks'])
            ->orderBy('id', 'desc');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->query('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return ReleaseResource::collection($query->get());
    }

    /**
     * Store a newly created release.
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $request->workspace();

        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'version' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'task_ids' => ['nullable', 'array'],
            'task_ids.*' => ['integer', 'exists:tasks,id'],
        ]);

        /** @var Project $project */
        $project = $workspace->projects()->findOrFail($validated['project_id']);

        /** @var Release $release */
        $release = $workspace->releases()->create([
            'project_id' => $project->id,
            'version' => $validated['version'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => 'draft',
            'logs' => ['[' . now()->format('H:i:s') . '] Release criada como rascunho.'],
        ]);

        if (! empty($validated['task_ids'])) {
            $workspace->tasks()
                ->whereIn('id', $validated['task_ids'])
                ->update(['release_id' => $release->id]);
        }

        $release->load(['project', 'tasks']);

        return (new ReleaseResource($release))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified release.
     */
    public function show(Request $request, Release $release): JsonResponse
    {
        $this->ensureWorkspaceRelease($request, $release);
        $release->load(['project', 'tasks']);

        return (new ReleaseResource($release))->response();
    }

    /**
     * Update the specified release.
     */
    public function update(Request $request, Release $release): JsonResponse
    {
        $this->ensureWorkspaceRelease($request, $release);
        $workspace = $request->workspace();

        $validated = $request->validate([
            'version' => ['sometimes', 'required', 'string', 'max:50'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:draft,sandbox,production,cancelled'],
            'release_notes' => ['nullable', 'string'],
            'task_ids' => ['nullable', 'array'],
            'task_ids.*' => ['integer', 'exists:tasks,id'],
        ]);

        $release->update(collect($validated)->except('task_ids')->toArray());

        if (array_key_exists('task_ids', $validated)) {
            // Unlink current tasks from this release
            $release->tasks()->update(['release_id' => null]);

            if (! empty($validated['task_ids'])) {
                $workspace->tasks()
                    ->whereIn('id', $validated['task_ids'])
                    ->update(['release_id' => $release->id]);
            }
        }

        $release->load(['project', 'tasks']);

        return (new ReleaseResource($release))->response();
    }

    /**
     * Trigger deploy of all selected release tasks to the 'release' branch (Homelab Sandbox).
     */
    public function deploySandbox(Request $request, Release $release): JsonResponse
    {
        $this->ensureWorkspaceRelease($request, $release);

        try {
            $result = $this->aiDispatcher->deployReleaseToSandbox($release);
            $release->refresh()->load(['project', 'tasks']);

            return response()->json([
                'message' => "Release {$release->version} deployada com sucesso na branch 'release' (Sandbox).",
                'result' => $result,
                'release' => new ReleaseResource($release),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Erro ao realizar deploy da release no Sandbox.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Promote release from 'release' branch to 'main' branch (Production deploy).
     */
    public function deployProduction(Request $request, Release $release): JsonResponse
    {
        $this->ensureWorkspaceRelease($request, $release);

        try {
            $result = $this->aiDispatcher->deployReleaseToProduction($release);
            $release->refresh()->load(['project', 'tasks']);

            return response()->json([
                'message' => "Release {$release->version} promovida com sucesso para a branch 'main' (Produção).",
                'result' => $result,
                'release' => new ReleaseResource($release),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Erro ao promover release para Produção.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete the release (unlinks all tasks).
     */
    public function destroy(Request $request, Release $release): JsonResponse
    {
        $this->ensureWorkspaceRelease($request, $release);

        $release->tasks()->update(['release_id' => null]);
        $release->delete();

        return response()->json(['message' => 'Release removida com sucesso.']);
    }

    private function ensureWorkspaceRelease(Request $request, Release $release): void
    {
        if ($release->workspace_id !== $request->workspace()->id) {
            abort(403, 'Acesso não autorizado a esta release.');
        }
    }
}
