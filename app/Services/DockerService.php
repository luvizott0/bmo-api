<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DockerService
{
    private string $socketPath;
    private ?string $sshHost;

    public function __construct()
    {
        $this->socketPath = config('services.docker.socket', '/var/run/docker.sock');
        $this->sshHost = config('services.docker.ssh_host', env('DOCKER_SSH_HOST', 'calebe@192.168.5.254'));
    }

    /**
     * List all containers (active and stopped).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listContainers(): array
    {
        $response = $this->request('GET', '/containers/json?all=1');

        if (! is_array($response)) {
            Log::warning('DockerService: Invalid response format when listing containers', ['response' => $response]);
            return [];
        }

        $containers = array_map(fn (array $c) => $this->formatContainer($c), $response);

        // Sort: running containers first, then alphabetically by project and name
        usort($containers, function ($a, $b) {
            if ($a['is_running'] !== $b['is_running']) {
                return $a['is_running'] ? -1 : 1;
            }
            $projCompare = strcmp((string) $a['project'], (string) $b['project']);
            if ($projCompare !== 0) {
                return $projCompare;
            }
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $containers;
    }

    /**
     * Start a container.
     */
    public function startContainer(string $idOrName): array
    {
        $cleanId = $this->sanitizeId($idOrName);
        $res = $this->request('POST', "/containers/{$cleanId}/start");
        return [
            'success' => in_array($res['code'] ?? 0, [204, 304]),
            'status_code' => $res['code'] ?? null,
            'message' => ($res['code'] ?? 0) === 304 ? 'Container já estava em execução.' : 'Container iniciado com sucesso.',
        ];
    }

    /**
     * Stop a container.
     */
    public function stopContainer(string $idOrName): array
    {
        $cleanId = $this->sanitizeId($idOrName);
        $res = $this->request('POST', "/containers/{$cleanId}/stop?t=10");
        return [
            'success' => in_array($res['code'] ?? 0, [204, 304]),
            'status_code' => $res['code'] ?? null,
            'message' => ($res['code'] ?? 0) === 304 ? 'Container já estava parado.' : 'Container parado com sucesso.',
        ];
    }

    /**
     * Restart a container.
     */
    public function restartContainer(string $idOrName): array
    {
        $cleanId = $this->sanitizeId($idOrName);
        $res = $this->request('POST', "/containers/{$cleanId}/restart?t=10");
        return [
            'success' => ($res['code'] ?? 0) === 204,
            'status_code' => $res['code'] ?? null,
            'message' => ($res['code'] ?? 0) === 204 ? 'Container reiniciado com sucesso.' : 'Falha ao reiniciar container.',
        ];
    }

    /**
     * Fetch the last logs of a container.
     */
    public function getContainerLogs(string $idOrName, int $tail = 100): string
    {
        $cleanId = $this->sanitizeId($idOrName);
        $res = $this->requestRaw('GET', "/containers/{$cleanId}/logs?stdout=1&stderr=1&tail={$tail}");
        return $this->cleanDockerMultiplexedStream($res['body'] ?? '');
    }

    /**
     * Format a raw Docker API container object into a clean structured array.
     */
    private function formatContainer(array $raw): array
    {
        $name = isset($raw['Names'][0]) ? ltrim($raw['Names'][0], '/') : 'unknown';
        $labels = $raw['Labels'] ?? [];
        $state = strtolower($raw['State'] ?? 'unknown');
        $isRunning = $state === 'running';

        $project = $labels['com.docker.compose.project'] ?? $this->inferProjectFromName($name);
        $service = $labels['com.docker.compose.service'] ?? $name;

        $ports = [];
        if (! empty($raw['Ports']) && is_array($raw['Ports'])) {
            foreach ($raw['Ports'] as $p) {
                if (isset($p['PublicPort'])) {
                    $ports[] = ($p['IP'] && $p['IP'] !== '0.0.0.0' ? "{$p['IP']}:" : '') . "{$p['PublicPort']}->{$p['PrivatePort']}/{$p['Type']}";
                } elseif (isset($p['PrivatePort'])) {
                    $ports[] = "{$p['PrivatePort']}/{$p['Type']}";
                }
            }
        }

        $createdTimestamp = (int) ($raw['Created'] ?? 0);
        $createdAt = $createdTimestamp > 0 ? date('Y-m-d H:i:s', $createdTimestamp) : null;

        // Health status
        $health = null;
        if (isset($raw['Health']['Status'])) {
            $health = $raw['Health']['Status'];
        } elseif (isset($raw['Status']) && str_contains(strtolower($raw['Status']), '(healthy)')) {
            $health = 'healthy';
        } elseif (isset($raw['Status']) && str_contains(strtolower($raw['Status']), '(unhealthy)')) {
            $health = 'unhealthy';
        }

        return [
            'id' => $raw['Id'] ?? '',
            'short_id' => isset($raw['Id']) ? substr($raw['Id'], 0, 12) : '',
            'name' => $name,
            'project' => $project,
            'service' => $service,
            'image' => $raw['Image'] ?? '',
            'state' => $state,
            'status' => $raw['Status'] ?? '',
            'is_running' => $isRunning,
            'health' => $health,
            'ports' => array_values(array_unique($ports)),
            'created' => $createdTimestamp,
            'created_at' => $createdAt,
            'last_updated' => $raw['Status'] ?? $createdAt,
        ];
    }

    /**
     * Infer a project or stack group if compose label is missing.
     */
    private function inferProjectFromName(string $name): string
    {
        $lower = strtolower($name);
        if (str_starts_with($lower, 'bmo-') || $lower === 'bmo') {
            return 'bmo';
        }
        if (str_starts_with($lower, 'capihouse-') || $lower === 'capihouse') {
            return 'capihouse';
        }
        if (str_starts_with($lower, 'capyshowtime-') || $lower === 'capyshowtime') {
            return 'capyshowtime';
        }
        if (str_starts_with($lower, 'capyflix-') || $lower === 'capyflix') {
            return 'capyflix';
        }
        if (str_starts_with($lower, 'evolution-') || $lower === 'evolution') {
            return 'evolution';
        }
        if (in_array($lower, ['mysql', 'mariadb', 'postgres', 'redis'])) {
            return 'database';
        }
        if (in_array($lower, ['nginx-proxy-manager', 'npm', 'adguardhome', 'adguard'])) {
            return 'network';
        }
        if (in_array($lower, ['portainer', 'jellyfin', 'teamspeak', 'sabrina-bot'])) {
            return 'services';
        }
        return 'standalone';
    }

    /**
     * Execute an HTTP request against the Docker Engine API.
     */
    private function request(string $method, string $endpoint, ?array $postData = null): mixed
    {
        $raw = $this->requestRaw($method, $endpoint, $postData);
        if ($raw['code'] >= 200 && $raw['code'] < 300 && ! empty($raw['body'])) {
            $decoded = json_decode($raw['body'], true);
            return $decoded !== null ? $decoded : $raw;
        }

        return $raw;
    }

    /**
     * Low-level communication via Unix socket (preferred) or SSH fallback.
     */
    private function requestRaw(string $method, string $endpoint, ?array $postData = null): array
    {
        if (file_exists($this->socketPath)) {
            return $this->curlUnixSocket($method, $endpoint, $postData);
        }

        // Dev machine fallback: Execute via SSH to Homelab BMO
        if ($this->sshHost) {
            return $this->sshFallback($method, $endpoint, $postData);
        }

        Log::error("DockerService: Cannot connect. Socket '{$this->socketPath}' not found and no SSH host configured.");
        return ['code' => 500, 'body' => 'Docker socket indisponível.', 'error' => 'Socket not found'];
    }

    /**
     * Direct Unix Socket cURL execution.
     */
    private function curlUnixSocket(string $method, string $endpoint, ?array $postData = null): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->socketPath);
        curl_setopt($ch, CURLOPT_URL, 'http://localhost' . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($postData !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            } else {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Length: 0']);
            }
        } elseif (strtoupper($method) === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'code' => $code,
            'body' => $body ?: '',
            'error' => $error ?: null,
        ];
    }

    /**
     * SSH Fallback for development outside of the Linux container.
     */
    private function sshFallback(string $method, string $endpoint, ?array $postData = null): array
    {
        $escapedEndpoint = escapeshellarg("http://localhost{$endpoint}");
        $curlCmd = "curl -s -i -X " . strtoupper($method) . " --unix-socket /var/run/docker.sock {$escapedEndpoint}";

        $sshCmd = "ssh -o BatchMode=yes -o ConnectTimeout=5 " . escapeshellarg($this->sshHost) . " " . escapeshellarg($curlCmd);

        $result = Process::timeout(35)->run($sshCmd);

        if (! $result->successful()) {
            Log::error('DockerService SSH fallback failed', [
                'error' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ]);
            return [
                'code' => 500,
                'body' => $result->errorOutput(),
                'error' => 'SSH command execution failed',
            ];
        }

        $output = $result->output();

        // Split HTTP headers from body
        $parts = explode("\r\n\r\n", $output, 2);
        if (count($parts) < 2) {
            $parts = explode("\n\n", $output, 2);
        }

        $headers = $parts[0] ?? '';
        $body = $parts[1] ?? $output;

        // Parse HTTP status code from first line of response (e.g. "HTTP/1.1 200 OK" or "HTTP/1.1 204 No Content")
        $code = 200;
        if (preg_match('/HTTP\/[0-9.]+\s+([0-9]{3})/', $headers, $matches)) {
            $code = (int) $matches[1];
        }

        return [
            'code' => $code,
            'body' => $body,
            'error' => null,
        ];
    }

    /**
     * Strip Docker multiplexed header bytes (8 bytes: [STREAM_TYPE, 0, 0, 0, SIZE1, SIZE2, SIZE3, SIZE4]) from log output.
     */
    private function cleanDockerMultiplexedStream(string $raw): string
    {
        if (empty($raw)) {
            return '';
        }

        $len = strlen($raw);
        $offset = 0;
        $clean = '';

        while ($offset < $len) {
            // Check if there is an 8-byte header
            if ($offset + 8 <= $len) {
                $type = ord($raw[$offset]);
                if (in_array($type, [1, 2])) { // 1 = stdout, 2 = stderr
                    $frameSize = (ord($raw[$offset + 4]) << 24)
                        | (ord($raw[$offset + 5]) << 16)
                        | (ord($raw[$offset + 6]) << 8)
                        | ord($raw[$offset + 7]);

                    if ($frameSize > 0 && ($offset + 8 + $frameSize) <= $len) {
                        $clean .= substr($raw, $offset + 8, $frameSize);
                        $offset += 8 + $frameSize;
                        continue;
                    }
                }
            }

            // Fallback: If not multiplexed or remaining bytes
            $clean .= substr($raw, $offset);
            break;
        }

        return $clean;
    }

    private function sanitizeId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $id);
    }
}
