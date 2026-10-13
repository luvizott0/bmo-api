<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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

    /**
     * Extract utility bill / invoice details from base64 PDF/image and/or email text.
     *
     * @return array{
     *     success: bool,
     *     provider: ?string,
     *     bill_type: ?string,
     *     amount: ?float,
     *     due_date: ?string,
     *     reference_month: ?string,
     *     pix_code: ?string,
     *     barcode: ?string
     * }
     */
    public function extractInvoice(
        ?string $base64 = null,
        string $mimetype = 'application/pdf',
        ?string $filename = null,
        string $text = '',
        string $subject = '',
        string $sender = ''
    ): array {
        try {
            $response = $this->postToExtractor('/extract-invoice', [
                'base64' => $base64,
                'mimetype' => $mimetype,
                'filename' => $filename,
                'text' => $text,
                'subject' => $subject,
                'sender' => $sender,
            ], 30);

            if ($response && $response->successful()) {
                $data = $response->json();

                return [
                    'success' => (bool) ($data['success'] ?? false),
                    'provider' => $data['provider'] ?? null,
                    'bill_type' => $data['bill_type'] ?? null,
                    'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
                    'due_date' => $data['due_date'] ?? null,
                    'reference_month' => $data['reference_month'] ?? null,
                    'pix_code' => $data['pix_code'] ?? null,
                    'barcode' => $data['barcode'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('ReceiptExtractorService extractInvoice failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'success' => false,
            'provider' => null,
            'bill_type' => null,
            'amount' => null,
            'due_date' => null,
            'reference_month' => null,
            'pix_code' => null,
            'barcode' => null,
        ];
    }

    /**
     * Connect to IMAP via Python extractor service and retrieve recent CPFL/Alares invoices.
     *
     * @return array{
     *     success: bool,
     *     error?: string,
     *     scanned?: int,
     *     invoices: array<int, array<string, mixed>>
     * }
     */
    public function fetchImapInvoices(
        string $emailAddress,
        ?string $password = null,
        ?string $accessToken = null,
        string $host = 'outlook.office365.com',
        int $port = 993,
        string $folder = 'INBOX',
        int $maxMessages = 400
    ): array {
        try {
            $response = $this->postToExtractor('/fetch-imap-invoices', [
                'email_address' => $emailAddress,
                'password' => $password,
                'access_token' => $accessToken,
                'host' => $host,
                'port' => $port,
                'folder' => $folder,
                'max_messages' => $maxMessages,
            ], 60);

            if ($response && $response->successful()) {
                $data = $response->json();

                return [
                    'success' => (bool) ($data['success'] ?? false),
                    'error' => $data['error'] ?? null,
                    'scanned' => (int) ($data['scanned'] ?? 0),
                    'invoices' => is_array($data['invoices'] ?? null) ? $data['invoices'] : [],
                ];
            }

            return [
                'success' => false,
                'error' => $response ? 'Extractor HTTP status '.$response->status() : 'No response from extractor',
                'invoices' => [],
            ];
        } catch (\Throwable $e) {
            Log::error('ReceiptExtractorService fetchImapInvoices failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'invoices' => [],
            ];
        }
    }

    /**
     * Post to the Python extractor service trying the configured URL first, then Docker/LAN fallbacks on connection error.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postToExtractor(string $endpointPath, array $payload, int $timeout = 30): ?Response
    {
        $candidates = $this->getCandidateBaseUrls();
        $lastException = null;

        foreach ($candidates as $baseUrl) {
            $url = rtrim($baseUrl, '/').'/'.ltrim($endpointPath, '/');
            try {
                return Http::timeout($timeout)->post($url, $payload);
            } catch (ConnectionException $e) {
                $lastException = $e;

                continue;
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function getCandidateBaseUrls(): array
    {
        $primary = $this->resolveBaseUrl();

        $fallbacks = [
            $primary,
            'http://bmo-receipt-extractor:5000',
            'http://receipt-extractor:5000',
            'http://192.168.5.254:8084',
            'http://127.0.0.1:8084',
        ];

        return array_values(array_unique(array_filter($fallbacks)));
    }

    private function resolveBaseUrl(): string
    {
        if ($this->extractorUrl) {
            return (string) preg_replace('#/extract$#', '', $this->extractorUrl);
        }

        $configuredBase = config('services.receipt_extractor.base_url');
        if ($configuredBase) {
            return (string) $configuredBase;
        }

        $extractUrl = (string) config('services.receipt_extractor.url', 'http://bmo-receipt-extractor:5000/extract');

        return (string) preg_replace('#/extract$#', '', $extractUrl);
    }
}
