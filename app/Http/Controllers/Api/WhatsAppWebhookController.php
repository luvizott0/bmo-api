<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppReceiptProcessorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppReceiptProcessorService $processorService
    ) {}

    /**
     * Handle incoming webhooks from Evolution API.
     */
    public function handle(Request $request): JsonResponse
    {
        $expectedSecret = config('services.evolution.webhook_secret');
        if ($expectedSecret) {
            $token = $request->header('X-Webhook-Token') ?? $request->query('token');
            if ($token !== $expectedSecret) {
                return response()->json(['message' => 'Unauthorized webhook access.'], 401);
            }
        }

        $this->processorService->processWebhook($request->all());

        return response()->json(['status' => 'ok']);
    }
}
