<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiTaskDispatcherService
{
    public function __construct(
        private readonly WhatsAppNotificationService $whatsAppService
    ) {}

    /**
     * Dispatch an AI development task to the bmo-ai-worker service.
     */
    public function dispatchTask(Task $task): bool
    {
        $project = $task->project;
        if (! $project || ! $project->github_repo) {
            Log::warning("AI Task Dispatcher: Task {$task->task_code} has no valid GitHub repository configured.");
            $this->appendLog($task, 'Erro: Projeto sem repositório do GitHub configurado.');
            return false;
        }

        $workerUrl = rtrim(config('services.ai_worker.url'), '/') . '/dispatch';
        $geminiKey = config('services.gemini.api_key');
        $githubToken = config('services.github.token');

        if (! $geminiKey || ! $githubToken) {
            Log::warning("AI Task Dispatcher: GEMINI_API_KEY or GITHUB_TOKEN missing.");
            $this->appendLog($task, 'Aviso: GEMINI_API_KEY ou GITHUB_TOKEN não configurados no BMO.');
            return false;
        }

        $payload = [
            'task_id' => $task->id,
            'task_code' => $task->task_code,
            'task_title' => $task->title,
            'task_description' => $task->description ?? '',
            'github_repo' => $project->github_repo,
            'base_branch' => $project->base_branch ?: 'develop',
            'branch_name' => $task->branch_name,
            'test_command' => $project->test_command ?: 'npm run build',
            'instructions_file' => $project->instructions_file ?: 'AGENTS.md',
            'callback_url' => url("/webhooks/tasks/{$task->id}/ai-callback"),
            'gemini_api_key' => $geminiKey,
            'github_token' => $githubToken,
        ];

        try {
            $this->appendLog($task, 'Disparando Agente de IA no BMO Homelab...');

            $response = Http::timeout(10)->post($workerUrl, $payload);

            if ($response->successful()) {
                $this->appendLog($task, 'Agente de IA iniciado com sucesso na fila do Homelab.');
                return true;
            }

            Log::error('AI Task Dispatcher failed to start worker', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->appendLog($task, "Falha ao iniciar worker: {$response->body()}");
            return false;
        } catch (\Throwable $e) {
            Log::error('AI Task Dispatcher connection error', ['error' => $e->getMessage()]);
            $this->appendLog($task, "Erro de conexão com o worker: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Handle the callback sent by the AI worker upon finishing execution.
     */
    public function handleCallback(Task $task, array $data): void
    {
        $status = $data['status'] ?? 'failed';
        $summary = $data['summary'] ?? '';
        $logs = $data['logs'] ?? [];

        $aiLogs = $task->ai_logs ?? [];
        $aiLogs['last_execution'] = now()->toISOString();
        $aiLogs['summary'] = $summary;
        $aiLogs['logs'] = array_merge($aiLogs['logs'] ?? [], $logs);
        $aiLogs['test_result'] = $data['test_result'] ?? 'unknown';

        if ($status === 'success') {
            $task->update([
                'status' => 'review',
                'ai_logs' => $aiLogs,
            ]);

            // Notify WhatsApp Project Group
            $projectGroupJid = config('services.whatsapp.projects_group_jid') ?: config('services.evolution.projects_group_jid');
            if ($projectGroupJid) {
                $project = $task->project;
                $prefix = $project?->prefix ?? 'PRJ';
                $projectName = $project?->name ?? 'Projeto';

                $sandboxDomain = strtolower($prefix) . '.bmo';
                if ($prefix === 'CPH') {
                    $sandboxDomain = 'capihouse.bmo';
                } elseif ($prefix === 'CST') {
                    $sandboxDomain = 'capyshowtime.bmo';
                }

                $msg = "🔔 *[{$task->task_code}] Tarefa desenvolvida pela IA!*\n\n"
                    . "📁 *Projeto:* {$projectName}\n"
                    . "🎯 *Título:* {$task->title}\n"
                    . "🌿 *Branch:* `{$task->branch_name}`\n"
                    . "🧪 *Sandbox:* http://{$sandboxDomain}\n\n"
                    . "📝 *Resumo:* {$summary}\n\n"
                    . "👉 Abra o board do *BMO Organizer* para validar e aprovar o merge para `develop`!";

                $this->whatsAppService->sendText($projectGroupJid, $msg);
            }
        } else {
            $aiLogs['error'] = $data['error'] ?? 'Falha desconhecida na execução.';
            $task->update([
                'ai_logs' => $aiLogs,
            ]);
        }
    }

    /**
     * Merge the completed feature branch into the base branch (e.g. develop) on GitHub.
     */
    public function mergeToBaseBranch(Task $task): bool
    {
        $project = $task->project;
        $githubToken = config('services.github.token');

        if (! $project || ! $project->github_repo || ! $githubToken || ! $task->branch_name) {
            return false;
        }

        $repo = trim($project->github_repo, '/');
        $baseBranch = $project->base_branch ?: 'develop';
        $headBranch = $task->branch_name;

        // GitHub API: POST /repos/{owner}/{repo}/merges
        $url = "https://api.github.com/repos/{$repo}/merges";

        try {
            $response = Http::withToken($githubToken)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post($url, [
                    'base' => $baseBranch,
                    'head' => $headBranch,
                    'commit_message' => "Merge feat({$task->task_code}): {$task->title} into {$baseBranch}",
                ]);

            if ($response->successful()) {
                $this->appendLog($task, "Merge automático para {$baseBranch} realizado com sucesso via GitHub API.");
                return true;
            }

            Log::warning('GitHub merge failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->appendLog($task, "Falha no merge automático para {$baseBranch}: " . $response->body());
            return false;
        } catch (\Throwable $e) {
            Log::error('GitHub merge request error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function appendLog(Task $task, string $message): void
    {
        $aiLogs = $task->ai_logs ?? [];
        $aiLogs['logs'] = $aiLogs['logs'] ?? [];
        $aiLogs['logs'][] = '[' . now()->format('H:i:s') . '] ' . $message;
        $task->update(['ai_logs' => $aiLogs]);
    }
}
