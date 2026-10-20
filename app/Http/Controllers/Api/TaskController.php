<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Services\AiTaskDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class TaskController extends Controller
{
    public function __construct(
        private readonly AiTaskDispatcherService $aiDispatcher
    ) {}
    /**
     * Display a listing of tasks for the active workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->workspace()
            ->tasks()
            ->with('project')
            ->orderBy('order', 'asc')
            ->orderBy('id', 'desc');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->query('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $tasks = $query->get();

        return TaskResource::collection($tasks);
    }

    /**
     * Get tasks currently in progress across all projects (used by UI & WhatsApp bot).
     */
    public function inProgress(Request $request): AnonymousResourceCollection
    {
        $tasks = $request->workspace()
            ->tasks()
            ->with('project')
            ->where('status', 'in_progress')
            ->orderBy('updated_at', 'desc')
            ->get();

        return TaskResource::collection($tasks);
    }

    /**
     * Store a newly created task.
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $request->workspace();

        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:feature,bugfix,hotfix'],
            'status' => ['nullable', 'string', 'in:backlog,todo,in_progress,review,reviewed,done'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'assignee_type' => ['nullable', 'string', 'in:user,ai'],
            'due_date' => ['nullable', 'date'],
            'branch_name' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Project $project */
        $project = $workspace->projects()->findOrFail($validated['project_id']);

        $allocated = $project->allocateNextTaskCode();
        $taskNumber = $allocated['number'];
        $taskCode = $allocated['code'];

        $type = $validated['type'] ?? 'feature';
        $branchPrefix = match ($type) {
            'hotfix' => 'hotfix',
            'bugfix' => 'fix',
            default => 'feat',
        };

        $slugTitle = Str::slug(Str::limit($validated['title'], 40, ''));
        $defaultBranch = "{$branchPrefix}/{$taskCode}-{$slugTitle}";

        $status = $validated['status'] ?? 'todo';

        $task = $workspace->tasks()->create([
            'project_id' => $project->id,
            'task_code' => $taskCode,
            'task_number' => $taskNumber,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'type' => $type,
            'status' => $status,
            'priority' => $validated['priority'] ?? 'medium',
            'branch_name' => $validated['branch_name'] ?? $defaultBranch,
            'assignee_type' => $validated['assignee_type'] ?? 'user',
            'due_date' => $validated['due_date'] ?? null,
            'completed_at' => $status === 'done' ? now() : null,
            'order' => 0,
        ]);

        $task->load('project');

        return (new TaskResource($task))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified task.
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        $this->ensureWorkspaceTask($request, $task);
        $task->load('project');

        return (new TaskResource($task))->response();
    }

    /**
     * Update the specified task.
     */
    public function update(Request $request, Task $task): JsonResponse
    {
        $this->ensureWorkspaceTask($request, $task);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'string', 'in:feature,bugfix,hotfix'],
            'status' => ['sometimes', 'string', 'in:backlog,todo,in_progress,review,reviewed,done'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high,urgent'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'assignee_type' => ['sometimes', 'string', 'in:user,ai'],
            'due_date' => ['nullable', 'date'],
            'order' => ['sometimes', 'integer'],
            'ai_logs' => ['nullable', 'array'],
        ]);

        if (isset($validated['status'])) {
            if ($validated['status'] === 'done' && $task->status !== 'done') {
                $validated['completed_at'] = now();
            } elseif ($validated['status'] !== 'done') {
                $validated['completed_at'] = null;
            }
        }

        $task->update($validated);
        $task->load('project');

        return (new TaskResource($task))->response();
    }

    /**
     * Update task status and order (convenience endpoint for drag-and-drop).
     */
    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        $this->ensureWorkspaceTask($request, $task);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:backlog,todo,in_progress,review,reviewed,done'],
            'order' => ['nullable', 'integer'],
        ]);

        $oldStatus = $task->status;
        $newStatus = $validated['status'];

        $updates = ['status' => $newStatus];
        if (isset($validated['order'])) {
            $updates['order'] = $validated['order'];
        }

        if ($newStatus === 'done' && $oldStatus !== 'done') {
            $updates['completed_at'] = now();
        } elseif ($newStatus !== 'done') {
            $updates['completed_at'] = null;
        }

        $task->update($updates);
        $task->load('project');

        // Automatic AI Trigger when moving to in_progress
        if ($newStatus === 'in_progress' && $oldStatus !== 'in_progress' && $task->assignee_type === 'ai') {
            $this->aiDispatcher->dispatchTask($task);
        }

        // Automatic Merge to Base Branch (e.g. develop) when moving to 'reviewed'
        if ($newStatus === 'reviewed') {
            if ($task->branch_name) {
                $merged = $this->aiDispatcher->mergeToBaseBranch($task);
                if ($merged) {
                    $task->update([
                        'status' => 'done',
                        'completed_at' => now(),
                    ]);
                    $task->refresh();
                }
            } else {
                $task->update([
                    'status' => 'done',
                    'completed_at' => now(),
                ]);
                $task->refresh();
            }
        }

        return (new TaskResource($task))->response();
    }

    /**
     * Webhook endpoint called by the AI Worker to report completion or progress.
     */
    public function aiCallback(Request $request, Task $task): JsonResponse
    {
        $this->aiDispatcher->handleCallback($task, $request->all());

        return response()->json(['status' => 'ok']);
    }

    /**
     * Remove the specified task.
     */
    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->ensureWorkspaceTask($request, $task);

        $task->delete();

        return response()->json(['message' => 'Tarefa removida com sucesso.']);
    }

    /**
     * Ensure task belongs to the active workspace.
     */
    private function ensureWorkspaceTask(Request $request, Task $task): void
    {
        if ($task->workspace_id !== $request->workspace()->id) {
            abort(403, 'Acesso não autorizado a esta tarefa.');
        }
    }
}
