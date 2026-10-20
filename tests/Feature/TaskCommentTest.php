<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Project $project;
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Homelab',
            'owner_id' => $this->user->id,
            'is_personal' => true,
        ]);

        $this->user->update(['default_workspace_id' => $this->workspace->id]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);

        $this->project = Project::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'CapiHouse',
            'slug' => 'capihouse',
            'prefix' => 'CAPI',
            'color' => '#10b981',
            'base_branch' => 'develop',
        ]);

        $this->task = Task::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'task_code' => 'CAPI-1',
            'task_number' => 1,
            'title' => 'Implement Auth Flow',
            'description' => 'Setup JWT/Sanctum authentication',
            'status' => 'todo',
            'priority' => 'high',
            'assignee_type' => 'ai',
        ]);
    }

    public function test_user_can_add_comment_to_task(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/api/tasks/{$this->task->id}/comments", [
            'content' => 'O endpoint /api/auth/login está retornando 500 no sandbox.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.content', 'O endpoint /api/auth/login está retornando 500 no sandbox.')
            ->assertJsonPath('data.author_type', 'user')
            ->assertJsonPath('data.task_id', $this->task->id);

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'content' => 'O endpoint /api/auth/login está retornando 500 no sandbox.',
        ]);
    }

    public function test_user_can_list_comments_for_task(): void
    {
        $this->actingAs($this->user);

        TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'content' => 'Primeiro comentário de teste',
            'author_name' => $this->user->name,
            'author_type' => 'user',
        ]);

        TaskComment::create([
            'task_id' => $this->task->id,
            'content' => 'Resposta do Agente de IA',
            'author_name' => 'BMO Agente de IA',
            'author_type' => 'ai',
        ]);

        $response = $this->getJson("/api/tasks/{$this->task->id}/comments");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_delete_comment(): void
    {
        $this->actingAs($this->user);

        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'content' => 'Comentário a ser deletado',
            'author_name' => $this->user->name,
            'author_type' => 'user',
        ]);

        $response = $this->deleteJson("/api/tasks/{$this->task->id}/comments/{$comment->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Comentário removido com sucesso.']);

        $this->assertDatabaseMissing('task_comments', [
            'id' => $comment->id,
        ]);
    }

    public function test_task_endpoint_includes_comments(): void
    {
        $this->actingAs($this->user);

        TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'content' => 'Feedback registrado',
            'author_name' => $this->user->name,
            'author_type' => 'user',
        ]);

        $response = $this->getJson("/api/tasks/{$this->task->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.content', 'Feedback registrado');
    }
}
