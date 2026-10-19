<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects for the current workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = $request->workspace()
            ->projects()
            ->withCount('tasks')
            ->orderBy('name')
            ->get();

        return ProjectResource::collection($projects);
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $request->workspace();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'prefix' => [
                'required',
                'string',
                'min:2',
                'max:10',
                'regex:/^[A-Za-z0-9]+$/',
                Rule::unique('projects', 'prefix')->where('workspace_id', $workspace->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'github_repo' => ['nullable', 'string', 'max:255'],
            'local_path' => ['nullable', 'string', 'max:500'],
            'base_branch' => ['nullable', 'string', 'max:50'],
            'test_command' => ['nullable', 'string', 'max:255'],
            'instructions_file' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $validated['prefix'] = strtoupper($validated['prefix']);

        $project = $workspace->projects()->create($validated);

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified project.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->ensureWorkspaceProject($request, $project);

        $project->loadCount('tasks');

        return (new ProjectResource($project))->response();
    }

    /**
     * Update the specified project.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->ensureWorkspaceProject($request, $project);
        $workspace = $request->workspace();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'prefix' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:10',
                'regex:/^[A-Za-z0-9]+$/',
                Rule::unique('projects', 'prefix')->where('workspace_id', $workspace->id)->ignore($project->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'github_repo' => ['nullable', 'string', 'max:255'],
            'local_path' => ['nullable', 'string', 'max:500'],
            'base_branch' => ['nullable', 'string', 'max:50'],
            'test_command' => ['nullable', 'string', 'max:255'],
            'instructions_file' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['prefix'])) {
            $validated['prefix'] = strtoupper($validated['prefix']);
        }

        $project->update($validated);

        return (new ProjectResource($project))->response();
    }

    /**
     * Remove the specified project.
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->ensureWorkspaceProject($request, $project);

        $project->delete();

        return response()->json(['message' => 'Projeto excluído com sucesso.']);
    }

    /**
     * Ensure the project belongs to the current workspace.
     */
    private function ensureWorkspaceProject(Request $request, Project $project): void
    {
        if ($project->workspace_id !== $request->workspace()->id) {
            abort(403, 'Acesso não autorizado a este projeto.');
        }
    }
}
