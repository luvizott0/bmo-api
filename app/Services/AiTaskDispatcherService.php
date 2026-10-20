<?php

namespace App\Services;

use App\Models\Release;
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
        if (! $project) {
            $this->appendLog($task, 'Erro: Projeto não encontrado.');
            return false;
        }

        // Determine repo, test command, and instructions file based on task target_scope or project configuration
        $targetScope = $task->target_scope ?? 'auto';
        $repo = null;
        $testCommand = null;
        $instructionsFile = null;

        if ($targetScope === 'backend' && $project->backend_repo) {
            $repo = $project->backend_repo;
            $testCommand = $project->backend_test_cmd ?: 'php artisan test';
            $instructionsFile = $project->backend_instructions_file ?: 'AGENTS.md';
        } elseif ($targetScope === 'frontend' && $project->frontend_repo) {
            $repo = $project->frontend_repo;
            $testCommand = $project->frontend_test_cmd ?: 'npm run build';
            $instructionsFile = $project->frontend_instructions_file ?: 'AGENTS.md';
        } else {
            // Auto or fullstack: prefer frontend_repo if set, then backend_repo, then legacy github_repo
            $repo = $project->frontend_repo ?: ($project->backend_repo ?: $project->github_repo);
            $testCommand = ($repo === $project->backend_repo ? $project->backend_test_cmd : $project->frontend_test_cmd)
                ?: ($project->test_command ?: 'npm run build');
            $instructionsFile = ($repo === $project->backend_repo ? $project->backend_instructions_file : $project->frontend_instructions_file)
                ?: ($project->instructions_file ?: 'AGENTS.md');
        }

        if (! $repo) {
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

        $comments = $task->comments()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($c) => [
                'author' => $c->author_name,
                'type' => $c->author_type,
                'content' => $c->content,
                'created_at' => $c->created_at->toISOString(),
            ])
            ->toArray();

        $branchPrefix = match ($task->type) {
            'hotfix' => 'hotfix',
            'bugfix' => 'fix',
            default => 'feat',
        };
        $cleanBranch = "{$branchPrefix}/{$task->task_code}";
        if (empty($task->branch_name) || str_contains($task->branch_name, "{$task->task_code}-")) {
            $task->update(['branch_name' => $cleanBranch]);
        }

        $payload = [
            'task_id' => $task->id,
            'task_code' => $task->task_code,
            'task_title' => $task->title,
            'task_description' => $task->description ?? '',
            'comments' => $comments,
            'github_repo' => $repo,
            'base_branch' => $project->base_branch ?: 'develop',
            'branch_name' => $task->branch_name,
            'test_command' => $testCommand,
            'instructions_file' => $instructionsFile,
            'callback_url' => url("/api/webhooks/tasks/{$task->id}/ai-callback"),
            'gemini_api_key' => $geminiKey,
            'github_token' => $githubToken,
        ];

        try {
            if (! empty($comments)) {
                $this->appendLog($task, sprintf('Revisão detectada: incluindo %d comentário(s) de feedback no contexto da IA.', count($comments)));
            }
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

        if ($status === 'running') {
            $logMessage = $data['log'] ?? null;
            if ($logMessage) {
                $this->appendLog($task, $logMessage);
            }
            return;
        }

        $summary = $data['summary'] ?? '';
        $logs = $data['logs'] ?? [];
        $previewUrl = $data['preview_url'] ?? ('http://' . strtolower($task->task_code) . '.preview.bmo');

        $aiLogs = $task->ai_logs ?? [];
        $aiLogs['last_execution'] = now()->toISOString();
        $aiLogs['summary'] = $summary;
        $aiLogs['logs'] = array_merge($aiLogs['logs'] ?? [], $logs);
        $aiLogs['test_result'] = $data['test_result'] ?? 'unknown';
        $aiLogs['preview_url'] = $previewUrl;

        if ($status === 'success') {
            $task->update([
                'status' => 'review',
                'ai_logs' => $aiLogs,
            ]);

            // Notify WhatsApp Project Group
            $projectGroupJid = config('services.whatsapp.projects_group_jid') ?: config('services.evolution.projects_group_jid');
            if ($projectGroupJid) {
                $project = $task->project;
                $projectName = $project?->name ?? 'Projeto';

                $msg = "🔔 *[{$task->task_code}] Tarefa desenvolvida pela IA!*\n\n"
                    . "📁 *Projeto:* {$projectName}\n"
                    . "🎯 *Título:* {$task->title}\n"
                    . "🌿 *Branch:* `{$task->branch_name}`\n"
                    . "🧪 *Preview Isolado:* {$previewUrl}\n\n"
                    . "📝 *Resumo:* {$summary}\n\n"
                    . "👉 Abra o board do *BMO Organizer* para testar no preview e aprovar a tarefa!";

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

        if (! $project || ! $githubToken || ! $task->branch_name) {
            return false;
        }

        $targetScope = $task->target_scope ?? 'auto';
        $repo = null;
        if ($targetScope === 'backend' && $project->backend_repo) {
            $repo = $project->backend_repo;
        } elseif ($targetScope === 'frontend' && $project->frontend_repo) {
            $repo = $project->frontend_repo;
        } else {
            $repo = $project->frontend_repo ?: ($project->backend_repo ?: $project->github_repo);
        }

        if (! $repo) {
            return false;
        }

        $repo = trim($repo, '/');
        // Hotfix merges directly to main; features and bugfixes merge to base branch (develop)
        $isHotfix = ($task->type === 'hotfix');
        $baseBranch = $isHotfix ? 'main' : ($project->base_branch ?: 'develop');
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

    /**
     * Deploy all tasks associated with a release into the 'release' branch (Homelab Sandbox deploy).
     */
    public function deployReleaseToSandbox(Release $release): array
    {
        $project = $release->project;
        $githubToken = config('services.github.token');

        if (! $project || ! $project->github_repo || ! $githubToken) {
            throw new \RuntimeException('Repositório do GitHub ou token não configurado para o projeto.');
        }

        $repo = trim($project->github_repo, '/');
        $release->load('tasks');
        $tasks = $release->tasks;

        if ($tasks->isEmpty()) {
            throw new \RuntimeException('Nenhuma tarefa vinculada a esta release para deploy.');
        }

        $logs = $release->logs ?? [];
        $logs[] = '[' . now()->format('H:i:s') . "] Iniciando deploy da release {$release->version} para o Sandbox...";

        $mergedTasks = [];
        $errors = [];

        foreach ($tasks as $task) {
            $branch = $task->branch_name;
            if (! $branch) {
                continue;
            }

            // GitHub API: POST /repos/{owner}/{repo}/merges
            $url = "https://api.github.com/repos/{$repo}/merges";

            try {
                $response = Http::withToken($githubToken)
                    ->withHeaders([
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->post($url, [
                        'base' => 'release',
                        'head' => $branch,
                        'commit_message' => "Release {$release->version}: Merge {$task->task_code} ({$task->title}) into release",
                    ]);

                if ($response->successful()) {
                    $logs[] = '[' . now()->format('H:i:s') . "] ✅ Branch '{$branch}' ({$task->task_code}) mergeada na 'release'.";
                    $mergedTasks[] = $task->task_code;
                } else {
                    $errText = $response->json('message') ?? $response->body();
                    $logs[] = '[' . now()->format('H:i:s') . "] ⚠️ Falha ao mergear branch '{$branch}': {$errText}";
                    $errors[] = "{$task->task_code}: {$errText}";
                }
            } catch (\Throwable $e) {
                $logs[] = '[' . now()->format('H:i:s') . "] ❌ Erro ao conectar com GitHub ({$branch}): {$e->getMessage()}";
                $errors[] = "{$task->task_code}: {$e->getMessage()}";
            }
        }

        $release->status = 'sandbox';
        $release->deployed_to_sandbox_at = now();
        $release->logs = $logs;
        $release->save();

        // Notificar no WhatsApp
        $projectGroupJid = config('services.whatsapp.projects_group_jid') ?: config('services.evolution.projects_group_jid');
        if ($projectGroupJid) {
            $projectName = $project->name ?? 'Projeto';
            $prefix = $project->prefix ?? 'PRJ';
            $sandboxDomain = strtolower($prefix) . '.bmo';
            if ($prefix === 'CPH') {
                $sandboxDomain = 'capihouse.bmo';
            } elseif ($prefix === 'CST') {
                $sandboxDomain = 'capyshowtime.bmo';
            }

            $tasksList = implode(', ', $mergedTasks);
            $msg = "🚀 *[{$projectName}] Release {$release->version} Deployada no Sandbox!*\n\n"
                . "🏷️ *Título:* {$release->title}\n"
                . "📋 *Tarefas:* {$tasksList}\n"
                . "🧪 *Sandbox:* http://{$sandboxDomain}\n\n"
                . "👉 Valide as funcionalidades integradas no sandbox antes de promover para produção!";

            $this->whatsAppService->sendText($projectGroupJid, $msg);
        }

        return [
            'success' => count($mergedTasks) > 0,
            'merged_tasks' => $mergedTasks,
            'errors' => $errors,
            'logs' => $logs,
        ];
    }

    /**
     * Promote the tested release from 'release' branch into 'main' branch (Production deploy).
     */
    public function deployReleaseToProduction(Release $release): array
    {
        $project = $release->project;
        $githubToken = config('services.github.token');

        if (! $project || ! $project->github_repo || ! $githubToken) {
            throw new \RuntimeException('Repositório do GitHub ou token não configurado para o projeto.');
        }

        $repo = trim($project->github_repo, '/');
        $release->load('tasks');

        $logs = $release->logs ?? [];
        $logs[] = '[' . now()->format('H:i:s') . "] Promovendo Release {$release->version} da branch 'release' para 'main' (PRODUÇÃO)...";

        // GitHub API: POST /repos/{owner}/{repo}/merges
        $url = "https://api.github.com/repos/{$repo}/merges";

        try {
            $response = Http::withToken($githubToken)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post($url, [
                    'base' => 'main',
                    'head' => 'release',
                    'commit_message' => "Release {$release->version}: {$release->title} (Promoted to Production)",
                ]);

            if ($response->successful()) {
                $logs[] = '[' . now()->format('H:i:s') . "] ✅ Branch 'release' mergeada com sucesso na 'main'!";
            } else {
                $errText = $response->json('message') ?? $response->body();
                $logs[] = '[' . now()->format('H:i:s') . "] ❌ Falha no merge para 'main': {$errText}";
                $release->logs = $logs;
                $release->save();
                throw new \RuntimeException("Falha ao promover release para main: {$errText}");
            }
        } catch (\Throwable $e) {
            $logs[] = '[' . now()->format('H:i:s') . "] ❌ Erro de comunicação com o GitHub: {$e->getMessage()}";
            $release->logs = $logs;
            $release->save();
            throw $e;
        }

        $release->status = 'production';
        $release->deployed_to_production_at = now();
        $release->logs = $logs;
        $release->save();

        // Marcar todas as tarefas vinculadas como 'done'
        foreach ($release->tasks as $t) {
            if ($t->status !== 'done') {
                $t->update([
                    'status' => 'done',
                    'completed_at' => now(),
                ]);
            }
        }

        // Notificar no WhatsApp
        $projectGroupJid = config('services.whatsapp.projects_group_jid') ?: config('services.evolution.projects_group_jid');
        if ($projectGroupJid) {
            $projectName = $project->name ?? 'Projeto';
            $msg = "🎉 *[{$projectName}] Release {$release->version} Oficialmente em PRODUÇÃO!*\n\n"
                . "🏷️ *Título:* {$release->title}\n"
                . "🚀 Deploy automático disparado para Oracle Cloud + Cloudflare Pages!";

            $this->whatsAppService->sendText($projectGroupJid, $msg);
        }

        return [
            'success' => true,
            'logs' => $logs,
        ];
    }

    private function appendLog(Task $task, string $message): void
    {
        $aiLogs = $task->ai_logs ?? [];
        $aiLogs['logs'] = $aiLogs['logs'] ?? [];
        $aiLogs['logs'][] = '[' . now()->format('H:i:s') . '] ' . $message;
        $task->update(['ai_logs' => $aiLogs]);
    }
}
