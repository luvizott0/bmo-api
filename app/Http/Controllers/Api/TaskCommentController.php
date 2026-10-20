<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskCommentController extends Controller
{
    /**
     * Display a listing of comments for the specified task.
     */
    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->ensureWorkspaceTask($request, $task);

        return TaskCommentResource::collection($task->comments()->orderBy('created_at', 'asc')->get());
    }

    /**
     * Store a newly created comment for the task.
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $this->ensureWorkspaceTask($request, $task);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        /** @var TaskComment $comment */
        $comment = $task->comments()->create([
            'user_id' => $user?->id,
            'author_name' => $user?->name ?? 'Desenvolvedor',
            'author_type' => 'user',
            'content' => $validated['content'],
        ]);

        return (new TaskCommentResource($comment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove the specified comment.
     */
    public function destroy(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $this->ensureWorkspaceTask($request, $task);

        if ($comment->task_id !== $task->id) {
            abort(404, 'Comentário não pertence a esta tarefa.');
        }

        $comment->delete();

        return response()->json(['message' => 'Comentário removido com sucesso.']);
    }

    private function ensureWorkspaceTask(Request $request, Task $task): void
    {
        if ($task->workspace_id !== $request->workspace()->id) {
            abort(403, 'Acesso não autorizado a esta tarefa.');
        }
    }
}
