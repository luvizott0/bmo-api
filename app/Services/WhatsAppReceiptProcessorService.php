<?php

namespace App\Services;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\Log;

class WhatsAppReceiptProcessorService
{
    public function __construct(
        private readonly ReceiptExtractorService $extractorService,
        private readonly WhatsAppNotificationService $notificationService,
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * Process an incoming Evolution API webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        $event = $payload['event'] ?? null;
        if ($event !== 'messages.upsert') {
            return;
        }

        $rawMessages = $payload['data']['messages'] ?? ($payload['data'] ?? []);
        if (isset($rawMessages['key'])) {
            $rawMessages = [$rawMessages];
        }

        if (! is_array($rawMessages)) {
            return;
        }

        foreach ($rawMessages as $msg) {
            $this->handleMessage($msg);
        }
    }

    /**
     * Handle an individual message from WhatsApp.
     *
     * @param  array<string, mixed>  $msg
     */
    public function handleMessage(array $msg): void
    {
        $key = $msg['key'] ?? [];
        $fromMe = (bool) ($key['fromMe'] ?? false);

        // Ignore messages sent by the bot itself
        if ($fromMe) {
            return;
        }

        $remoteJid = $key['remoteJid'] ?? null;
        if (! $remoteJid) {
            return;
        }

        $messageId = $key['id'] ?? null;
        $participant = $key['participant'] ?? ($msg['participant'] ?? $remoteJid);
        $senderPhone = $this->extractDigits($participant);
        $pushName = $msg['pushName'] ?? '';

        $messageObj = $msg['message'] ?? [];
        $mediaType = $msg['messageType'] ?? null;

        $isImage = $mediaType === 'imageMessage' || isset($messageObj['imageMessage']);
        $isDocument = $mediaType === 'documentMessage' || isset($messageObj['documentMessage']) || isset($messageObj['documentWithCaptionMessage']);

        if (! $isImage && ! $isDocument) {
            return;
        }

        // Get base64 payload
        $base64 = $messageObj['base64'] ?? ($msg['base64'] ?? null);
        if (! $base64) {
            Log::info('WhatsApp message with media has no base64 attached', ['message_id' => $messageId]);

            return;
        }

        $mimetype = 'image/jpeg';
        $filename = null;

        if ($isDocument) {
            $doc = $messageObj['documentMessage'] ?? ($messageObj['documentWithCaptionMessage'] ?? []);
            $mimetype = $doc['mimetype'] ?? 'application/pdf';
            $filename = $doc['fileName'] ?? ($doc['title'] ?? 'comprovante.pdf');
        } elseif ($isImage) {
            $img = $messageObj['imageMessage'] ?? [];
            $mimetype = $img['mimetype'] ?? 'image/jpeg';
        }

        // Extract receipt data via Python extractor
        $extracted = $this->extractorService->extractFromBase64($base64, $mimetype, $filename);

        if (! ($extracted['success'] ?? false) || empty($extracted['amount'])) {
            $caption = $this->extractCaption($messageObj);
            $hasReceiptKeyword = str_contains(strtolower($caption), 'comprovante') || str_contains(strtolower($caption), 'pix');

            if ($hasReceiptKeyword || ! str_contains($remoteJid, '@g.us')) {
                $this->notificationService->sendText(
                    $remoteJid,
                    '⚠️ Não consegui extrair as informações deste comprovante. Por favor, envie uma imagem com melhor nitidez ou o arquivo PDF original.',
                    $messageId
                );
            }

            return;
        }

        $amount = (float) $extracted['amount'];
        $transactionId = $extracted['transaction_id'] ?? null;
        $paymentDate = $extracted['payment_date'] ?? now()->toDateString();
        $referenceMonth = substr($paymentDate, 0, 7);

        // Anti-duplicity check via transaction ID
        if ($transactionId) {
            $alreadyExists = SubscriptionPayment::where('pix_e2e_id', $transactionId)->first();
            if ($alreadyExists) {
                $this->notificationService->sendText(
                    $remoteJid,
                    "⚠️ Este comprovante já foi registrado anteriormente no sistema (Ciclo: {$alreadyExists->reference_month})!",
                    $messageId
                );

                return;
            }
        }

        // Match member
        $member = $this->findMember($senderPhone, $extracted['payer_name'] ?? null, $pushName, $amount, $referenceMonth);

        if (! $member) {
            $formattedAmount = number_format($amount, 2, ',', '.');
            $senderLabel = $pushName ?: ($extracted['payer_name'] ?? 'Remetente');

            $this->notificationService->sendText(
                $remoteJid,
                "⚠️ Recebi um comprovante no valor de R$ {$formattedAmount} ({$senderLabel}), mas não identifiquei nenhuma assinatura pendente correspondente no BMO.",
                $messageId
            );

            return;
        }

        // Validate recipient against Workspace Owner name (if extracted)
        $workspace = $member->subscription->workspace;
        $ownerName = $workspace?->owner?->name;

        if ($ownerName && ! empty($extracted['recipient_name'])) {
            if (! $this->namesMatch($ownerName, $extracted['recipient_name'])) {
                $this->notificationService->sendText(
                    $remoteJid,
                    "⚠️ O recebedor no comprovante ('{$extracted['recipient_name']}') não confere com o titular da conta ('{$ownerName}').",
                    $messageId
                );

                return;
            }
        }

        // Record payment
        $this->subscriptionService->recordMemberPayment(
            member: $member,
            referenceMonth: $referenceMonth,
            status: SubscriptionPaymentStatus::Paid->value,
            amount: $amount,
            paymentDate: $paymentDate,
            pixE2EId: $transactionId,
            metadata: $extracted,
            userId: $workspace?->owner_id
        );

        // Send confirmation
        $formattedAmount = number_format($amount, 2, ',', '.');
        $serviceName = $member->subscription->service_name;
        $cycleLabel = implode('/', array_reverse(explode('-', $referenceMonth)));

        $reply = "✅ *Pagamento Confirmado!*\n".
                 "👤 *Membro:* {$member->name}\n".
                 "📺 *Assinatura:* {$serviceName}\n".
                 "💰 *Valor:* R$ {$formattedAmount}\n".
                 "📅 *Ciclo:* {$cycleLabel}";

        if ($transactionId) {
            $shortId = strlen($transactionId) > 20 ? substr($transactionId, 0, 10).'...'.substr($transactionId, -6) : $transactionId;
            $reply .= "\n🏦 *Autenticação:* {$shortId}";
        }

        $this->notificationService->sendText($remoteJid, $reply, $messageId);
    }

    /**
     * Find the best matching SubscriptionMember based on phone, name, and amount.
     */
    public function findMember(
        string $phoneDigits,
        ?string $payerName,
        string $pushName,
        float $amount,
        string $referenceMonth
    ): ?SubscriptionMember {
        $lastDigits = strlen($phoneDigits) >= 8 ? substr($phoneDigits, -8) : $phoneDigits;

        // Query active members
        $query = SubscriptionMember::where('is_active', true)
            ->whereHas('subscription', function ($q) {
                $q->where('is_active', true);
            })
            ->with(['subscription.workspace.owner', 'payments']);

        $candidates = $query->get();

        $matchedMembers = collect();

        // 1. Phone match
        if (! empty($lastDigits)) {
            $byPhone = $candidates->filter(function (SubscriptionMember $m) use ($lastDigits) {
                $memberPhone = $this->extractDigits($m->contact ?? '');

                return ! empty($memberPhone) && str_ends_with($memberPhone, $lastDigits);
            });

            if ($byPhone->isNotEmpty()) {
                $matchedMembers = $byPhone;
            }
        }

        // 2. Fallback to name match if no phone match
        if ($matchedMembers->isEmpty()) {
            $byName = $candidates->filter(function (SubscriptionMember $m) use ($payerName, $pushName) {
                if ($payerName && $this->namesMatch($m->name, $payerName)) {
                    return true;
                }
                if ($pushName && $this->namesMatch($m->name, $pushName)) {
                    return true;
                }

                return false;
            });

            if ($byName->isNotEmpty()) {
                $matchedMembers = $byName;
            }
        }

        if ($matchedMembers->isEmpty()) {
            return null;
        }

        // If only 1 member found, check if amount is close
        if ($matchedMembers->count() === 1) {
            return $matchedMembers->first();
        }

        // Filter by installment amount matching
        $byAmount = $matchedMembers->filter(function (SubscriptionMember $m) use ($amount) {
            return abs(((float) $m->installment_amount) - $amount) < 1.00;
        });

        if ($byAmount->count() === 1) {
            return $byAmount->first();
        }

        // Prioritize member who has a pending payment for this cycle
        $pending = ($byAmount->isNotEmpty() ? $byAmount : $matchedMembers)->filter(function (SubscriptionMember $m) use ($referenceMonth) {
            $payment = $m->payments->firstWhere('reference_month', $referenceMonth);

            return ! $payment || $payment->status !== SubscriptionPaymentStatus::Paid;
        });

        return $pending->first() ?: $matchedMembers->first();
    }

    /**
     * Check if two names match by first name or common tokens.
     */
    public function namesMatch(string $nameA, string $nameB): bool
    {
        $cleanA = mb_strtolower(trim(preg_replace('/[^a-zA-ZÀ-ÖØ-öø-ÿ\s]/u', '', $nameA)));
        $cleanB = mb_strtolower(trim(preg_replace('/[^a-zA-ZÀ-ÖØ-öø-ÿ\s]/u', '', $nameB)));

        if ($cleanA === $cleanB) {
            return true;
        }

        $partsA = array_filter(explode(' ', $cleanA), fn ($p) => strlen($p) > 2);
        $partsB = array_filter(explode(' ', $cleanB), fn ($p) => strlen($p) > 2);

        // Check if first name matches
        if (! empty($partsA) && ! empty($partsB) && reset($partsA) === reset($partsB)) {
            return true;
        }

        // Check intersection of tokens
        $common = array_intersect($partsA, $partsB);

        return count($common) >= 2;
    }

    /**
     * Extract only numeric digits from a string.
     */
    public function extractDigits(?string $str): string
    {
        return preg_replace('/\D/', '', $str ?? '') ?: '';
    }

    /**
     * Extract caption from image or document message.
     *
     * @param  array<string, mixed>  $messageObj
     */
    private function extractCaption(array $messageObj): string
    {
        return $messageObj['imageMessage']['caption']
            ?? ($messageObj['documentWithCaptionMessage']['caption']
            ?? ($messageObj['documentMessage']['caption'] ?? ''));
    }
}
