<?php

namespace App\Services;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Collection;
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

        // Fallback: If base64 was not sent directly in webhook payload, fetch from Evolution API
        if (! $base64 && $messageId) {
            $mediaData = $this->notificationService->getBase64FromMediaMessage($messageId);
            if ($mediaData && ! empty($mediaData['base64'])) {
                $base64 = $mediaData['base64'];
                $mimetype = $mediaData['mimetype'] ?? $mimetype;
                $filename = $mediaData['fileName'] ?? $filename;
            }
        }

        if (! $base64) {
            Log::info('WhatsApp message with media has no base64 attached and could not be fetched', ['message_id' => $messageId]);

            return;
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
     * Find the best matching SubscriptionMember based on receipt payer name, sender phone, or pushName.
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

        if ($candidates->isEmpty()) {
            return null;
        }

        // 1. Payer name match from receipt (highest priority when forwarded or sent in group)
        if (! empty($payerName)) {
            $byPayer = $candidates->filter(fn (SubscriptionMember $m) => $this->namesMatch($m->name, $payerName));

            if ($byPayer->isNotEmpty()) {
                return $this->resolveBestCandidate($byPayer, $amount, $referenceMonth);
            }
        }

        // 2. Phone match
        if (! empty($lastDigits)) {
            $byPhone = $candidates->filter(function (SubscriptionMember $m) use ($lastDigits) {
                $memberPhone = $this->extractDigits($m->contact ?? '');

                return ! empty($memberPhone) && str_ends_with($memberPhone, $lastDigits);
            });

            if ($byPhone->isNotEmpty()) {
                return $this->resolveBestCandidate($byPhone, $amount, $referenceMonth);
            }
        }

        // 3. Fallback to WhatsApp pushName
        if (! empty($pushName)) {
            $byPush = $candidates->filter(fn (SubscriptionMember $m) => $this->namesMatch($m->name, $pushName));

            if ($byPush->isNotEmpty()) {
                return $this->resolveBestCandidate($byPush, $amount, $referenceMonth);
            }
        }

        return null;
    }

    /**
     * Resolve the best candidate among matched members by amount and pending status.
     *
     * @param  Collection<int, SubscriptionMember>  $matchedMembers
     */
    private function resolveBestCandidate(Collection $matchedMembers, float $amount, string $referenceMonth): ?SubscriptionMember
    {
        if ($matchedMembers->count() === 1) {
            return $matchedMembers->first();
        }

        // Filter by installment amount matching (within 1.00 tolerance)
        $byAmount = $matchedMembers->filter(function (SubscriptionMember $m) use ($amount) {
            return abs(((float) $m->installment_amount) - $amount) < 1.00;
        });

        $pool = $byAmount->isNotEmpty() ? $byAmount : $matchedMembers;

        if ($pool->count() === 1) {
            return $pool->first();
        }

        // Prioritize member who has a pending payment for this cycle
        $pending = $pool->filter(function (SubscriptionMember $m) use ($referenceMonth) {
            $payment = $m->payments->firstWhere('reference_month', $referenceMonth);

            return ! $payment || $payment->status !== SubscriptionPaymentStatus::Paid;
        });

        return $pending->first() ?: $pool->first();
    }

    /**
     * Check if two names match by first name, common tokens, or nickname/diminutive stems.
     */
    public function namesMatch(string $nameA, string $nameB): bool
    {
        $cleanA = mb_strtolower(trim(preg_replace('/[^a-zA-ZÀ-ÖØ-öø-ÿ\s]/u', '', $nameA)));
        $cleanB = mb_strtolower(trim(preg_replace('/[^a-zA-ZÀ-ÖØ-öø-ÿ\s]/u', '', $nameB)));

        if ($cleanA === $cleanB) {
            return true;
        }

        $stopWords = ['da', 'de', 'do', 'das', 'dos', 'e'];
        $partsA = array_values(array_filter(explode(' ', $cleanA), fn ($p) => strlen($p) >= 2 && ! in_array($p, $stopWords, true)));
        $partsB = array_values(array_filter(explode(' ', $cleanB), fn ($p) => strlen($p) >= 2 && ! in_array($p, $stopWords, true)));

        if (empty($partsA) || empty($partsB)) {
            return false;
        }

        // Check if first name matches (including diminutives/stems)
        $firstA = $partsA[0];
        $firstB = $partsB[0];
        if ($this->tokensMatch($firstA, $firstB)) {
            return true;
        }

        // Check intersection of tokens
        $commonCount = 0;
        foreach ($partsA as $tA) {
            foreach ($partsB as $tB) {
                if ($this->tokensMatch($tA, $tB)) {
                    $commonCount++;
                    break;
                }
            }
        }

        return $commonCount >= 2;
    }

    /**
     * Check if two name tokens match, accounting for Brazilian diminutives and prefixes.
     */
    private function tokensMatch(string $tokenA, string $tokenB): bool
    {
        if ($tokenA === $tokenB) {
            return true;
        }

        $stemA = preg_replace('/(zinhos?|zinhas?|z[aã]os?|inhos?|inhas?|[aã]os?)$/u', '', $tokenA) ?? $tokenA;
        $stemB = preg_replace('/(zinhos?|zinhas?|z[aã]os?|inhos?|inhas?|[aã]os?)$/u', '', $tokenB) ?? $tokenB;

        if (strlen($stemA) >= 3 && strlen($stemB) >= 3) {
            if ($stemA === $stemB) {
                return true;
            }
            if (str_starts_with($stemA, $stemB) || str_starts_with($stemB, $stemA)) {
                return true;
            }
        }

        if (strlen($tokenA) >= 3 && strlen($tokenB) >= 3) {
            if (str_starts_with($tokenA, $tokenB) || str_starts_with($tokenB, $tokenA)) {
                return true;
            }
        }

        return false;
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
