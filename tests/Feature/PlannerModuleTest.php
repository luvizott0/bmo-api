<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Casa',
            'owner_id' => $this->user->id,
            'is_personal' => true,
        ]);

        $this->user->update(['default_workspace_id' => $this->workspace->id]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_can_create_project_and_tasks_with_auto_code(): void
    {
        $this->actingAs($this->user);

        // 1. Create project with prefix CST
        $projectResponse = $this->postJson('/api/projects', [
            'name' => 'CapyShowTime',
            'prefix' => 'cst',
            'github_repo' => 'luviz/capyshowtime-pwa',
            'color' => '#8b5cf6',
        ]);

        $projectResponse->assertStatus(201);
        $projectData = $projectResponse->json('data');
        $this->assertEquals('CST', $projectData['prefix']);
        $projectId = $projectData['id'];

        // 2. Create first task -> should get CST-001
        $task1Response = $this->postJson('/api/tasks', [
            'project_id' => $projectId,
            'title' => 'Implement video player',
            'priority' => 'high',
        ]);

        $task1Response->assertStatus(201);
        $task1Data = $task1Response->json('data');
        $this->assertEquals('CST-001', $task1Data['task_code']);
        $this->assertEquals('feat/CST-001-implement-video-player', $task1Data['branch_name']);

        // 3. Create second task -> should get CST-002
        $task2Response = $this->postJson('/api/tasks', [
            'project_id' => $projectId,
            'title' => 'Add subtitle selector',
            'priority' => 'medium',
        ]);

        $task2Response->assertStatus(201);
        $this->assertEquals('CST-002', $task2Response->json('data.task_code'));

        // 4. Update task 1 status to in_progress
        $statusResponse = $this->patchJson("/api/tasks/{$task1Data['id']}/status", [
            'status' => 'in_progress',
        ]);
        $statusResponse->assertStatus(200);
        $this->assertEquals('in_progress', $statusResponse->json('data.status'));

        // 5. Check in-progress tasks endpoint
        $inProgResponse = $this->getJson('/api/tasks/in-progress');
        $inProgResponse->assertStatus(200);
        $this->assertCount(1, $inProgResponse->json('data'));
        $this->assertEquals('CST-001', $inProgResponse->json('data.0.task_code'));
    }

    public function test_can_manage_calendar_events_and_reminders(): void
    {
        $this->actingAs($this->user);

        // Create Calendar Event
        $eventResponse = $this->postJson('/api/calendar-events', [
            'title' => 'Dentista',
            'start_at' => now()->addDay()->format('Y-m-d 14:00:00'),
            'end_at' => now()->addDay()->format('Y-m-d 15:00:00'),
            'category' => 'personal',
        ]);
        $eventResponse->assertStatus(201);
        $this->assertEquals('Dentista', $eventResponse->json('data.title'));

        // Create Reminder
        $reminderResponse = $this->postJson('/api/reminders', [
            'title' => 'Colocar lixo pra fora',
            'due_at' => now()->format('Y-m-d 20:00:00'),
            'category' => 'house',
        ]);
        $reminderResponse->assertStatus(201);
        $reminderId = $reminderResponse->json('data.id');

        // Toggle Reminder
        $toggleResponse = $this->patchJson("/api/reminders/{$reminderId}/toggle");
        $toggleResponse->assertStatus(200);
        $this->assertTrue($toggleResponse->json('data.is_completed'));
    }
}
