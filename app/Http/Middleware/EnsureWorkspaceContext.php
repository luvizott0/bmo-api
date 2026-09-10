<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Closure;
use Database\Seeders\CategorySeeder;
use Database\Seeders\StockCategorySeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');

        if ($workspaceId) {
            $workspace = $user->workspaces()->where('workspaces.id', $workspaceId)->first();

            if (! $workspace) {
                return new JsonResponse([
                    'message' => 'Espaço financeiro não encontrado ou você não tem permissão para acessá-lo.',
                ], 403);
            }
        } else {
            $workspace = $user->personalWorkspace();

            if (! $workspace) {
                // Create personal workspace if none exists yet
                $workspace = Workspace::create([
                    'owner_id' => $user->id,
                    'name' => 'Meu Espaço Pessoal',
                    'is_personal' => true,
                ]);

                $workspace->members()->attach($user->id, [
                    'role' => WorkspaceRole::Owner->value,
                ]);

                CategorySeeder::seedForWorkspace($workspace);
                StockCategorySeeder::seedForWorkspace($workspace);
            }
        }

        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
