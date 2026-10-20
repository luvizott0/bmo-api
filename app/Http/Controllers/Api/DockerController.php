<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DockerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DockerController extends Controller
{
    public function __construct(
        private readonly DockerService $dockerService
    ) {}

    /**
     * List all Docker containers running on the host/homelab machine.
     */
    public function index(): JsonResponse
    {
        $containers = $this->dockerService->listContainers();

        return response()->json([
            'data' => $containers,
            'meta' => [
                'total' => count($containers),
                'running' => count(array_filter($containers, fn ($c) => $c['is_running'])),
                'stopped' => count(array_filter($containers, fn ($c) => ! $c['is_running'])),
            ],
        ]);
    }

    /**
     * Start a specific Docker container.
     */
    public function start(string $idOrName): JsonResponse
    {
        $result = $this->dockerService->startContainer($idOrName);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => 'Falha ao iniciar o container.',
                'details' => $result,
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'container' => $idOrName,
        ]);
    }

    /**
     * Stop a specific Docker container.
     */
    public function stop(string $idOrName): JsonResponse
    {
        $result = $this->dockerService->stopContainer($idOrName);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => 'Falha ao parar o container.',
                'details' => $result,
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'container' => $idOrName,
        ]);
    }

    /**
     * Restart a specific Docker container.
     */
    public function restart(string $idOrName): JsonResponse
    {
        $result = $this->dockerService->restartContainer($idOrName);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => 'Falha ao reiniciar o container.',
                'details' => $result,
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'container' => $idOrName,
        ]);
    }

    /**
     * Get logs for a specific Docker container.
     */
    public function logs(Request $request, string $idOrName): JsonResponse
    {
        $tail = min(500, max(20, (int) $request->query('tail', 100)));
        $logs = $this->dockerService->getContainerLogs($idOrName, $tail);

        return response()->json([
            'container' => $idOrName,
            'tail' => $tail,
            'logs' => $logs,
        ]);
    }
}
