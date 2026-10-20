<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\DockerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DockerModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

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
    }

    public function test_can_list_containers(): void
    {
        $mock = $this->mock(DockerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('listContainers')
                ->once()
                ->andReturn([
                    [
                        'id' => '1234567890abcdef',
                        'short_id' => '1234567890ab',
                        'name' => 'capihouse-api',
                        'project' => 'capihouse',
                        'service' => 'capihouse-api',
                        'image' => 'capihouse-api:latest',
                        'state' => 'running',
                        'status' => 'Up 3 hours (healthy)',
                        'is_running' => true,
                        'health' => 'healthy',
                        'ports' => ['8006->8000/tcp'],
                        'created' => 1700000000,
                        'created_at' => '2026-10-19 12:00:00',
                        'last_updated' => 'Up 3 hours (healthy)',
                    ],
                    [
                        'id' => 'abcdef1234567890',
                        'short_id' => 'abcdef123456',
                        'name' => 'bmo-organizer',
                        'project' => 'bmo',
                        'service' => 'bmo-organizer',
                        'image' => 'bmo-organizer:latest',
                        'state' => 'exited',
                        'status' => 'Exited (0) 10 minutes ago',
                        'is_running' => false,
                        'health' => null,
                        'ports' => ['8087->80/tcp'],
                        'created' => 1700000000,
                        'created_at' => '2026-10-19 12:00:00',
                        'last_updated' => 'Exited (0) 10 minutes ago',
                    ],
                ]);
        });

        $this->actingAs($this->user);

        $response = $this->getJson('/api/docker/containers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'short_id',
                        'name',
                        'project',
                        'service',
                        'image',
                        'state',
                        'status',
                        'is_running',
                        'ports',
                    ],
                ],
                'meta' => [
                    'total',
                    'running',
                    'stopped',
                ],
            ]);

        $this->assertEquals(2, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.running'));
        $this->assertEquals(1, $response->json('meta.stopped'));
    }

    public function test_can_start_container(): void
    {
        $this->mock(DockerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startContainer')
                ->with('bmo-organizer')
                ->once()
                ->andReturn([
                    'success' => true,
                    'status_code' => 204,
                    'message' => 'Container iniciado com sucesso.',
                ]);
        });

        $this->actingAs($this->user);

        $response = $this->postJson('/api/docker/containers/bmo-organizer/start');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Container iniciado com sucesso.',
                'container' => 'bmo-organizer',
            ]);
    }

    public function test_can_stop_container(): void
    {
        $this->mock(DockerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('stopContainer')
                ->with('capihouse-api')
                ->once()
                ->andReturn([
                    'success' => true,
                    'status_code' => 204,
                    'message' => 'Container parado com sucesso.',
                ]);
        });

        $this->actingAs($this->user);

        $response = $this->postJson('/api/docker/containers/capihouse-api/stop');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Container parado com sucesso.',
                'container' => 'capihouse-api',
            ]);
    }

    public function test_can_restart_container(): void
    {
        $this->mock(DockerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('restartContainer')
                ->with('capihouse-api')
                ->once()
                ->andReturn([
                    'success' => true,
                    'status_code' => 204,
                    'message' => 'Container reiniciado com sucesso.',
                ]);
        });

        $this->actingAs($this->user);

        $response = $this->postJson('/api/docker/containers/capihouse-api/restart');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Container reiniciado com sucesso.',
                'container' => 'capihouse-api',
            ]);
    }

    public function test_can_get_container_logs(): void
    {
        $this->mock(DockerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getContainerLogs')
                ->with('capihouse-api', 50)
                ->once()
                ->andReturn("Starting server...\nReady for connections\n");
        });

        $this->actingAs($this->user);

        $response = $this->getJson('/api/docker/containers/capihouse-api/logs?tail=50');

        $response->assertStatus(200)
            ->assertJson([
                'container' => 'capihouse-api',
                'tail' => 50,
                'logs' => "Starting server...\nReady for connections\n",
            ]);
    }

    public function test_unauthenticated_user_cannot_access_docker_endpoints(): void
    {
        $response = $this->getJson('/api/docker/containers');
        $response->assertStatus(401);
    }
}
