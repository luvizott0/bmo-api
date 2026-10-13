<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\FixedBill;
use App\Models\IntegrationSetting;
use App\Models\Transaction;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailInvoiceSyncService
{
    public function __construct(
        private readonly ReceiptExtractorService $extractorService,
        private readonly WhatsAppNotificationService $whatsAppService
    ) {}

    /**
     * Initiate Microsoft OAuth2 Device Code Flow for Outlook IMAP access.
     *
     * @return array<string, mixed>
     */
    public function startOutlookDeviceAuth(bool $forceNew = false): array
    {
        if (! $forceNew) {
            $existingDeviceCode = IntegrationSetting::getValue('outlook_device_code');
            $existingUserCode = IntegrationSetting::getValue('outlook_user_code');
            $expiresAt = (int) IntegrationSetting::getValue('outlook_device_code_expires_at', '0');

            if ($existingDeviceCode && $existingUserCode && $expiresAt > now()->timestamp) {
                return [
                    'success' => true,
                    'user_code' => $existingUserCode,
                    'verification_uri' => 'https://microsoft.com/devicelogin',
                    'expires_in' => $expiresAt - now()->timestamp,
                    'interval' => 5,
                    'device_code' => $existingDeviceCode,
                    'reused' => true,
                ];
            }
        }

        $clientId = config('services.invoice_email.oauth_client_id', '9e5f94bc-e8a4-4e73-b8be-63364c29d753');

        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/devicecode', [
            'client_id' => $clientId,
            'scope' => 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access',
        ]);

        if (! $response->successful()) {
            return [
                'success' => false,
                'error' => 'Failed to initiate Microsoft device code flow: '.$response->body(),
            ];
        }

        $data = $response->json();
        $expiresIn = (int) ($data['expires_in'] ?? 900);

        if (! empty($data['device_code'])) {
            IntegrationSetting::setValue('outlook_device_code', $data['device_code']);
            IntegrationSetting::setValue('outlook_user_code', $data['user_code'] ?? '');
            IntegrationSetting::setValue('outlook_device_code_expires_at', (string) (now()->timestamp + $expiresIn - 60));
        }

        return [
            'success' => true,
            'user_code' => $data['user_code'] ?? null,
            'verification_uri' => $data['verification_uri'] ?? 'https://microsoft.com/devicelogin',
            'message' => $data['message'] ?? null,
            'expires_in' => $expiresIn,
            'interval' => $data['interval'] ?? 5,
            'device_code' => $data['device_code'] ?? null,
            'reused' => false,
        ];
    }

    /**
     * Poll Microsoft OAuth2 Token endpoint to exchange device_code for refresh_token + access_token.
     *
     * @return array{status: string, message?: string}
     */
    public function pollOutlookDeviceAuth(?string $deviceCode = null): array
    {
        $clientId = config('services.invoice_email.oauth_client_id', '9e5f94bc-e8a4-4e73-b8be-63364c29d753');
        $deviceCode = $deviceCode ?: IntegrationSetting::getValue('outlook_device_code');

        if (! $deviceCode) {
            return [
                'status' => 'error',
                'message' => 'No active device_code found. Run startOutlookDeviceAuth first.',
            ];
        }

        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $clientId,
            'device_code' => $deviceCode,
        ]);

        $data = $response->json();

        if ($response->successful() && ! empty($data['access_token'])) {
            IntegrationSetting::setValue('outlook_access_token', $data['access_token']);
            if (! empty($data['refresh_token'])) {
                IntegrationSetting::setValue('outlook_refresh_token', $data['refresh_token']);
            }
            IntegrationSetting::setValue('outlook_token_expires_at', (string) (now()->timestamp + (int) ($data['expires_in'] ?? 3600) - 60));

            return [
                'status' => 'authorized',
                'message' => 'Outlook OAuth2 autorizado com sucesso!',
            ];
        }

        $errorCode = $data['error'] ?? 'unknown_error';
        if ($errorCode === 'authorization_pending') {
            return [
                'status' => 'pending',
                'message' => 'Aguardando confirmação do código pelo usuário...',
            ];
        }

        return [
            'status' => 'error',
            'message' => $data['error_description'] ?? $errorCode,
        ];
    }

    /**
     * Obtain a valid OAuth2 access token using stored refresh_token if available.
     */
    public function getValidAccessToken(): ?string
    {
        $expiresAt = (int) IntegrationSetting::getValue('outlook_token_expires_at', '0');
        $cachedAccess = IntegrationSetting::getValue('outlook_access_token');

        if ($cachedAccess && $expiresAt > now()->timestamp) {
            return $cachedAccess;
        }

        $refreshToken = IntegrationSetting::getValue('outlook_refresh_token');
        if (! $refreshToken) {
            // Check if there is a pending device_code that the user just authorized
            $pendingDeviceCode = IntegrationSetting::getValue('outlook_device_code');
            if ($pendingDeviceCode) {
                $poll = $this->pollOutlookDeviceAuth($pendingDeviceCode);
                if ($poll['status'] === 'authorized') {
                    return IntegrationSetting::getValue('outlook_access_token');
                }
            }

            return null;
        }

        $clientId = config('services.invoice_email.oauth_client_id', '9e5f94bc-e8a4-4e73-b8be-63364c29d753');

        try {
            $response = Http::asForm()->timeout(15)->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'refresh_token' => $refreshToken,
                'scope' => 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $newAccess = $data['access_token'] ?? null;
                $newRefresh = $data['refresh_token'] ?? null;

                if ($newAccess) {
                    IntegrationSetting::setValue('outlook_access_token', $newAccess);
                    IntegrationSetting::setValue(
                        'outlook_token_expires_at',
                        (string) (now()->timestamp + (int) ($data['expires_in'] ?? 3600) - 60)
                    );
                }

                if ($newRefresh) {
                    IntegrationSetting::setValue('outlook_refresh_token', $newRefresh);
                }

                return $newAccess;
            }

            Log::warning('EmailInvoiceSyncService: Failed refreshing Outlook OAuth2 token', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('EmailInvoiceSyncService: Token refresh exception', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Connect to the configured email inbox, extract CPFL and Alares invoices, and update FixedBills.
     *
     * @return array{
     *     success: bool,
     *     error?: ?string,
     *     device_auth?: ?array<string, mixed>,
     *     scanned: int,
     *     synced: int,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function syncFromEmail(bool $notifyWhatsApp = true, ?int $workspaceId = null): array
    {
        $emailAddress = (string) config('services.invoice_email.address');
        $password = config('services.invoice_email.password');
        $host = (string) config('services.invoice_email.host', 'outlook.office365.com');
        $port = (int) config('services.invoice_email.port', 993);
        $folder = (string) config('services.invoice_email.folder', 'INBOX');

        if (! $emailAddress) {
            return [
                'success' => false,
                'error' => 'INVOICE_EMAIL_ADDRESS is not configured.',
                'scanned' => 0,
                'synced' => 0,
                'items' => [],
            ];
        }

        $accessToken = $this->getValidAccessToken();

        $fetchResult = $this->extractorService->fetchImapInvoices(
            emailAddress: $emailAddress,
            password: $password,
            accessToken: $accessToken,
            host: $host,
            port: $port,
            folder: $folder
        );

        // If XOAUTH2 failed and password is provided, try basic auth fallback
        if (! $fetchResult['success'] && $accessToken && $password) {
            $fetchResult = $this->extractorService->fetchImapInvoices(
                emailAddress: $emailAddress,
                password: $password,
                accessToken: null,
                host: $host,
                port: $port,
                folder: $folder
            );
        }

        if (! $fetchResult['success']) {
            $errorMsg = (string) ($fetchResult['error'] ?? 'Unknown IMAP error');
            $deviceAuth = null;

            if (stripos($errorMsg, 'Basic authentication is disabled') !== false || stripos($errorMsg, 'authentication failed') !== false) {
                $authStart = $this->startOutlookDeviceAuth();
                if ($authStart['success'] ?? false) {
                    $deviceAuth = $authStart;
                    $errorMsg = "Autorização única do Outlook necessária: acesse {$authStart['verification_uri']} e digite o código {$authStart['user_code']} (depois clique em Checar E-mails novamente).";

                    $financialJid = config('services.evolution.financial_group_jid');
                    if ($notifyWhatsApp && $financialJid && empty($authStart['reused'])) {
                        $this->whatsAppService->sendText(
                            $financialJid,
                            "🔐 *Autorização única do Outlook necessária no BMO:*\n\n1️⃣ Acesse: {$authStart['verification_uri']}\n2️⃣ Digite o código abaixo para liberar a leitura automática das faturas:"
                        );
                        $this->whatsAppService->sendText($financialJid, (string) $authStart['user_code']);
                    }
                }
            }

            return [
                'success' => false,
                'error' => $errorMsg,
                'device_auth' => $deviceAuth,
                'scanned' => 0,
                'synced' => 0,
                'items' => [],
            ];
        }

        $syncedItems = [];
        // Process oldest to newest so newer emails in the same month update older ones if needed
        $invoices = array_reverse($fetchResult['invoices']);

        foreach ($invoices as $invoiceData) {
            $result = $this->syncInvoicePayload($invoiceData, $notifyWhatsApp, null, $workspaceId);
            if ($result) {
                $syncedItems[] = $result;
            }
        }

        return [
            'success' => true,
            'error' => null,
            'scanned' => (int) ($fetchResult['scanned'] ?? 0),
            'synced' => count($syncedItems),
            'items' => $syncedItems,
        ];
    }

    /**
     * Sync a single extracted invoice payload into the matching FixedBill and optionally notify WhatsApp.
     *
     * @param  array<string, mixed>  $invoiceData
     * @return array<string, mixed>|null
     */
    public function syncInvoicePayload(
        array $invoiceData,
        bool $notifyWhatsApp = true,
        ?string $replyJid = null,
        ?int $workspaceId = null
    ): ?array {
        if (empty($invoiceData['amount']) || empty($invoiceData['provider'])) {
            return null;
        }

        $externalMessageId = $invoiceData['external_message_id'] ?? null;
        if ($externalMessageId) {
            $alreadyProcessed = Transaction::where('external_message_id', $externalMessageId)->first();
            if ($alreadyProcessed) {
                return null;
            }
        }

        $provider = (string) $invoiceData['provider'];
        $billType = $invoiceData['bill_type'] ?? null;
        $amount = (float) $invoiceData['amount'];
        $dueDateStr = $invoiceData['due_date'] ?? null;
        $referenceMonth = $invoiceData['reference_month']
            ?? ($dueDateStr ? substr($dueDateStr, 0, 7) : now()->format('Y-m'));
        $pixCode = $invoiceData['pix_code'] ?? null;
        $barcode = $invoiceData['barcode'] ?? null;

        $fixedBill = $this->findOrCreateMatchingFixedBill($provider, $billType, $amount, $dueDateStr, $workspaceId);
        if (! $fixedBill) {
            return null;
        }

        [$year, $month] = explode('-', $referenceMonth);
        $startOfMonth = Carbon::createFromDate((int) $year, (int) $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $dueDate = $dueDateStr
            ? Carbon::parse($dueDateStr)
            : Carbon::createFromDate(
                (int) $year,
                (int) $month,
                min((int) $fixedBill->due_day, $endOfMonth->day)
            );

        $existingTx = Transaction::where('fixed_bill_id', $fixedBill->id)
            ->whereBetween('occurred_at', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->orderByDesc('occurred_at')
            ->first();

        $isNewNotificationWorthy = false;

        if ($existingTx) {
            if ($existingTx->status === TransactionStatus::Paid) {
                // Already paid: only enrich missing invoice codes without altering paid status
                $existingTx->update([
                    'pix_code' => $existingTx->pix_code ?: $pixCode,
                    'barcode' => $existingTx->barcode ?: $barcode,
                    'external_message_id' => $existingTx->external_message_id ?: $externalMessageId,
                ]);
                $transaction = $existingTx;
            } else {
                // Pending transaction: check if amount or payment codes changed
                $amountChanged = abs((float) $existingTx->amount - $amount) >= 0.01;
                $codesAdded = (! $existingTx->pix_code && $pixCode) || (! $existingTx->barcode && $barcode);
                $isNewNotificationWorthy = $amountChanged || $codesAdded || ! $existingTx->external_message_id;

                $existingTx->update([
                    'amount' => $amount,
                    'occurred_at' => $dueDate->toDateString(),
                    'pix_code' => $pixCode ?: $existingTx->pix_code,
                    'barcode' => $barcode ?: $existingTx->barcode,
                    'external_message_id' => $externalMessageId ?: $existingTx->external_message_id,
                    'description' => "Fatura {$fixedBill->name} ({$referenceMonth})",
                ]);
                $transaction = $existingTx;
            }
        } else {
            $ownerId = $fixedBill->workspace?->owner_id ?? 1;

            $transaction = Transaction::create([
                'workspace_id' => $fixedBill->workspace_id,
                'created_by_user_id' => $ownerId,
                'category_id' => $fixedBill->category_id,
                'bank_account_id' => $fixedBill->preferred_bank_account_id,
                'fixed_bill_id' => $fixedBill->id,
                'type' => $fixedBill->type ?? TransactionType::Expense,
                'status' => TransactionStatus::Pending,
                'description' => "Fatura {$fixedBill->name} ({$referenceMonth})",
                'amount' => $amount,
                'occurred_at' => $dueDate->toDateString(),
                'pix_code' => $pixCode,
                'barcode' => $barcode,
                'external_message_id' => $externalMessageId,
            ]);

            $isNewNotificationWorthy = true;
        }

        if ($notifyWhatsApp && $isNewNotificationWorthy) {
            $this->sendNewInvoiceWhatsAppNotification($fixedBill, $transaction, $replyJid);
        }

        return [
            'fixed_bill_id' => $fixedBill->id,
            'fixed_bill_name' => $fixedBill->name,
            'transaction_id' => $transaction->id,
            'provider' => $provider,
            'amount' => (float) $transaction->amount,
            'due_date' => $transaction->occurred_at->format('Y-m-d'),
            'reference_month' => $referenceMonth,
            'has_pix' => ! empty($transaction->pix_code),
            'has_barcode' => ! empty($transaction->barcode),
        ];
    }

    /**
     * Find an active FixedBill matching the provider/billType, or create one if none exists.
     */
    private function findOrCreateMatchingFixedBill(
        string $provider,
        ?string $billType,
        float $amount,
        ?string $dueDateStr,
        ?int $workspaceId = null
    ): ?FixedBill {
        $query = FixedBill::with('workspace')->where('is_active', true);
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }
        $activeBills = $query->get();

        $keywords = match (strtolower($provider)) {
            'cpfl' => ['cpfl', 'luz', 'energia', 'elétric', 'eletric'],
            'alares' => ['alares', 'internet', 'wi-fi', 'wifi', 'fibra'],
            default => $billType === 'luz'
                ? ['cpfl', 'luz', 'energia', 'elétric', 'eletric']
                : ['alares', 'internet', 'wi-fi', 'wifi', 'fibra'],
        };

        foreach ($activeBills as $bill) {
            $lowerName = mb_strtolower($bill->name);
            foreach ($keywords as $kw) {
                if (str_contains($lowerName, $kw)) {
                    return $bill;
                }
            }
        }

        // If not found among active bills, check all bills (including other workspaces if workspaceId wasn't matched)
        $allBills = FixedBill::with('workspace')
            ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->get();

        foreach ($allBills as $bill) {
            $lowerName = mb_strtolower($bill->name);
            foreach ($keywords as $kw) {
                if (str_contains($lowerName, $kw)) {
                    $bill->update(['is_active' => true]);

                    return $bill;
                }
            }
        }

        // Auto-create in the target workspace (or primary workspace) if no bill exists yet
        $workspace = $workspaceId ? Workspace::find($workspaceId) : Workspace::first();
        if (! $workspace) {
            $workspace = Workspace::first();
        }
        if (! $workspace) {
            return null;
        }

        $dueDay = $dueDateStr ? (int) Carbon::parse($dueDateStr)->day : 10;
        $defaultName = strtolower($provider) === 'cpfl' || $billType === 'luz'
            ? 'Luz (CPFL)'
            : 'Internet (Alares)';

        return FixedBill::create([
            'workspace_id' => $workspace->id,
            'name' => $defaultName,
            'type' => TransactionType::Expense,
            'estimated_amount' => $amount,
            'due_day' => $dueDay,
            'is_active' => true,
        ]);
    }

    /**
     * Send proactive notification to the "financeiro" WhatsApp group when a new invoice is synced.
     */
    public function sendNewInvoiceWhatsAppNotification(
        FixedBill $bill,
        Transaction $transaction,
        ?string $targetJid = null
    ): void {
        $jid = $targetJid ?: config('services.evolution.financial_group_jid');
        if (! $jid) {
            return;
        }

        $formattedAmount = number_format((float) $transaction->amount, 2, ',', '.');
        $formattedDue = $transaction->occurred_at->format('d/m/Y');

        $lines = [
            '📬 *Nova conta atualizada no BMO!*',
            '',
            "🏠 *Conta:* {$bill->name}",
            "💰 *Valor deste mês:* R$ {$formattedAmount}",
            "📅 *Vencimento:* {$formattedDue}",
        ];

        if ($transaction->pix_code) {
            $lines[] = '';
            $lines[] = '💠 *Pix Copia e Cola disponível abaixo:*';
        } elseif ($transaction->barcode) {
            $lines[] = '';
            $lines[] = '🔢 *Código de Barras disponível abaixo:*';
        }

        $this->whatsAppService->sendText($jid, implode("\n", $lines));

        // Send raw Pix code or Barcode as a standalone message so it's 1-tap copyable in WhatsApp
        if ($transaction->pix_code) {
            $this->whatsAppService->sendText($jid, $transaction->pix_code);
        } elseif ($transaction->barcode) {
            $this->whatsAppService->sendText($jid, $transaction->barcode);
        }
    }
}
