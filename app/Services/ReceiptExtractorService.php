<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReceiptExtractorService
{
    public function __construct(
        private readonly ?string $extractorUrl = null
    ) {}

    /**
     * Send base64-encoded receipt to the extractor service.
     *
     * @return array{
     *     success: bool,
     *     amount: ?float,
     *     payment_date: ?string,
     *     transaction_id: ?string,
     *     payer_name: ?string,
     *     recipient_name: ?string,
     *     raw_text_length?: int
     * }
     */
    public function extractFromBase64(string $base64, string $mimetype = 'image/jpeg', ?string $filename = null): array
    {
        $url = $this->extractorUrl ?: config('services.receipt_extractor.url');

        try {
            $response = Http::timeout(30)->post($url, [
                'base64' => $base64,
                'mimetype' => $mimetype,
                'filename' => $filename,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => (bool) ($data['success'] ?? false),
                    'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
                    'payment_date' => $data['payment_date'] ?? null,
                    'transaction_id' => $data['transaction_id'] ?? null,
                    'payer_name' => $data['payer_name'] ?? null,
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'raw_text_length' => $data['raw_text_length'] ?? 0,
                ];
            }

            Log::warning('ReceiptExtractorService returned non-success response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ReceiptExtractorService request failed', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
        }

        return [
            'success' => false,
            'amount' => null,
            'payment_date' => null,
            'transaction_id' => null,
            'payer_name' => null,
            'recipient_name' => null,
        ];
    }
}
