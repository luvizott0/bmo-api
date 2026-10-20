<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class QuotaController extends Controller
{
    /**
     * Store synced quota data from Antigravity local agent.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_tier' => ['nullable', 'string'],
            'limit_5h' => ['nullable', 'array'],
            'limit_5h.remaining_percentage' => ['nullable', 'numeric'],
            'limit_5h.reset_time' => ['nullable', 'string'],
            'limit_5h.label' => ['nullable', 'string'],
            'limit_weekly' => ['nullable', 'array'],
            'limit_weekly.remaining_percentage' => ['nullable', 'numeric'],
            'limit_weekly.reset_time' => ['nullable', 'string'],
            'limit_weekly.label' => ['nullable', 'string'],
            'models' => ['nullable', 'array'],
        ]);

        $quotaData = [
            'user_tier' => $validated['user_tier'] ?? 'Google AI Pro',
            'limit_5h' => $validated['limit_5h'] ?? null,
            'limit_weekly' => $validated['limit_weekly'] ?? null,
            'models' => $validated['models'] ?? [],
            'updated_at' => now()->toIso8601String(),
        ];

        Cache::forever('antigravity_quota', $quotaData);

        return response()->json([
            'status' => 'ok',
            'message' => 'Cotas atualizadas com sucesso.',
            'data' => $quotaData,
        ]);
    }

    /**
     * Get the latest synced quota data.
     */
    public function get(): JsonResponse
    {
        $quota = Cache::get('antigravity_quota');

        if (! $quota) {
            return response()->json([
                'user_tier' => 'Google AI',
                'limit_5h' => null,
                'limit_weekly' => null,
                'models' => [],
                'updated_at' => null,
            ]);
        }

        return response()->json($quota);
    }
}
