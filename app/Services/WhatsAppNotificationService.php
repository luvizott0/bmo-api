<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
        private readonly ?string $instance = null
    ) {}

    /**
     * Send a text message to a WhatsApp chat or group.
     */
    public function sendText(string $remoteJid, string $text, ?string $quotedMessageId = null): bool
    {
        $baseUrl = rtrim($this->baseUrl ?: config('services.evolution.url'), '/');
        $apiKey = $this->apiKey ?: config('services.evolution.api_key');
        $instance = $this->instance ?: config('services.evolution.instance');

        $url = "{$baseUrl}/message/sendText/{$instance}";

        $payload = [
            'number' => $remoteJid,
            'text' => $text,
            'delay' => 500,
        ];

        if ($quotedMessageId) {
            $payload['quoted'] = [
                'key' => [
                    'id' => $quotedMessageId,
                ],
            ];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'apikey' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('WhatsAppNotificationService: failed to send message', [
                'status' => $response->status(),
                'body' => $response->body(),
                'remoteJid' => $remoteJid,
            ]);
        } catch (\Throwable $e) {
            Log::error('WhatsAppNotificationService request error', [
                'error' => $e->getMessage(),
                'remoteJid' => $remoteJid,
            ]);
        }

        return false;
    }
}
