<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReminderResource;
use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReminderController extends Controller
{
    /**
     * Display a listing of reminders.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->workspace()
            ->reminders()
            ->orderBy('is_completed', 'asc')
            ->orderBy('due_at', 'asc');

        if ($request->has('completed')) {
            $isCompleted = filter_var($request->query('completed'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_completed', $isCompleted);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        $reminders = $query->get();

        return ReminderResource::collection($reminders);
    }

    /**
     * Store a newly created reminder.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['required', 'date'],
            'category' => ['nullable', 'string', 'max:30'],
            'recurrence' => ['nullable', 'string', 'in:none,daily,weekly,monthly'],
            'notify_whatsapp' => ['boolean'],
        ]);

        $reminder = $request->workspace()->reminders()->create($validated);

        return (new ReminderResource($reminder))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified reminder.
     */
    public function show(Request $request, Reminder $reminder): JsonResponse
    {
        $this->ensureWorkspaceReminder($request, $reminder);

        return (new ReminderResource($reminder))->response();
    }

    /**
     * Toggle completion status of a reminder.
     */
    public function toggle(Request $request, Reminder $reminder): JsonResponse
    {
        $this->ensureWorkspaceReminder($request, $reminder);

        $newStatus = ! $reminder->is_completed;
        $reminder->update([
            'is_completed' => $newStatus,
            'completed_at' => $newStatus ? now() : null,
        ]);

        return (new ReminderResource($reminder))->response();
    }

    /**
     * Update the specified reminder.
     */
    public function update(Request $request, Reminder $reminder): JsonResponse
    {
        $this->ensureWorkspaceReminder($request, $reminder);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'due_at' => ['sometimes', 'required', 'date'],
            'is_completed' => ['sometimes', 'boolean'],
            'category' => ['nullable', 'string', 'max:30'],
            'recurrence' => ['nullable', 'string', 'in:none,daily,weekly,monthly'],
            'notify_whatsapp' => ['boolean'],
        ]);

        if (isset($validated['is_completed'])) {
            $validated['completed_at'] = $validated['is_completed'] ? now() : null;
        }

        $reminder->update($validated);

        return (new ReminderResource($reminder))->response();
    }

    /**
     * Remove the specified reminder.
     */
    public function destroy(Request $request, Reminder $reminder): JsonResponse
    {
        $this->ensureWorkspaceReminder($request, $reminder);

        $reminder->delete();

        return response()->json(['message' => 'Lembrete removido com sucesso.']);
    }

    /**
     * Ensure reminder belongs to the active workspace.
     */
    private function ensureWorkspaceReminder(Request $request, Reminder $reminder): void
    {
        if ($reminder->workspace_id !== $request->workspace()->id) {
            abort(403, 'Acesso não autorizado a este lembrete.');
        }
    }
}
