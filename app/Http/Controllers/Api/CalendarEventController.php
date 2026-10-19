<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Http\Resources\ReminderResource;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalendarEventController extends Controller
{
    /**
     * Display a listing of calendar events (supports date range).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->workspace()
            ->calendarEvents()
            ->orderBy('start_at', 'asc');

        if ($request->filled('start')) {
            $query->where('start_at', '>=', Carbon::parse($request->query('start'))->startOfDay());
        }

        if ($request->filled('end')) {
            $query->where('start_at', '<=', Carbon::parse($request->query('end'))->endOfDay());
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        $events = $query->get();

        return CalendarEventResource::collection($events);
    }

    /**
     * Summary of today's schedule and reminders (used for WhatsApp and overview dashboard).
     */
    public function todaySummary(Request $request): JsonResponse
    {
        $workspace = $request->workspace();
        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();

        $events = $workspace->calendarEvents()
            ->whereBetween('start_at', [$todayStart, $todayEnd])
            ->orderBy('start_at', 'asc')
            ->get();

        $reminders = $workspace->reminders()
            ->whereDate('due_at', Carbon::today())
            ->orderBy('due_at', 'asc')
            ->get();

        return response()->json([
            'date' => Carbon::today()->toDateString(),
            'events' => CalendarEventResource::collection($events),
            'reminders' => ReminderResource::collection($reminders),
        ]);
    }

    /**
     * Store a newly created calendar event.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['boolean'],
            'category' => ['nullable', 'string', 'max:30'],
            'color' => ['nullable', 'string', 'max:20'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $event = $request->workspace()->calendarEvents()->create($validated);

        return (new CalendarEventResource($event))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified calendar event.
     */
    public function show(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $this->ensureWorkspaceEvent($request, $calendarEvent);

        return (new CalendarEventResource($calendarEvent))->response();
    }

    /**
     * Update the specified calendar event.
     */
    public function update(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $this->ensureWorkspaceEvent($request, $calendarEvent);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_at' => ['sometimes', 'required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['boolean'],
            'category' => ['nullable', 'string', 'max:30'],
            'color' => ['nullable', 'string', 'max:20'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $calendarEvent->update($validated);

        return (new CalendarEventResource($calendarEvent))->response();
    }

    /**
     * Remove the specified calendar event.
     */
    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $this->ensureWorkspaceEvent($request, $calendarEvent);

        $calendarEvent->delete();

        return response()->json(['message' => 'Evento removido com sucesso.']);
    }

    /**
     * Ensure event belongs to current workspace.
     */
    private function ensureWorkspaceEvent(Request $request, CalendarEvent $event): void
    {
        if ($event->workspace_id !== $request->workspace()->id) {
            abort(403, 'Acesso não autorizado a este evento.');
        }
    }
}
