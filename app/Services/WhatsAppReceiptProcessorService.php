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

        // Match members (single or combo)
        $matchedMembers = $this->matchMembersForPayment($senderPhone, $extracted['payer_name'] ?? null, $pushName, $amount, $referenceMonth);

        if (empty($matchedMembers)) {
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
        $firstMember = $matchedMembers[0];
        $workspace = $firstMember->subscription->workspace;
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

        // Record payment for each member in the matched list
        foreach ($matchedMembers as $member) {
            $this->subscriptionService->recordMemberPayment(
                member: $member,
                referenceMonth: $referenceMonth,
                status: SubscriptionPaymentStatus::Paid->value,
                amount: (float) $member->installment_amount,
                paymentDate: $paymentDate,
                pixE2EId: $transactionId,
                metadata: $extracted,
                userId: $workspace?->owner_id
            );
        }

        // Send confirmation
        $formattedAmount = number_format($amount, 2, ',', '.');
        $cycleLabel = implode('/', array_reverse(explode('-', $referenceMonth)));

        if (count($matchedMembers) === 1) {
            $member = $matchedMembers[0];
            $serviceName = $member->subscription->service_name;

            $reply = "✅ *Pagamento Confirmado!*\n".
                     "👤 *Membro:* {$member->name}\n".
                     "📺 *Assinatura:* {$serviceName}\n".
                     "💰 *Valor:* R$ {$formattedAmount}\n".
                     "📅 *Ciclo:* {$cycleLabel}";
        } else {
            $memberName = $firstMember->name;

            $reply = "✅ *Pagamento Confirmado (Combo)!*\n".
                     "👤 *Membro:* {$memberName}\n".
                     "💰 *Total Recebido:* R$ {$formattedAmount}\n".
                     "📅 *Ciclo:* {$cycleLabel}\n\n".
                     "📺 *Assinaturas quitadas:*\n";

            foreach ($matchedMembers as $member) {
                $serviceName = $member->subscription->service_name;
                $memberAmount = number_format((float) $member->installment_amount, 2, ',', '.');
                $reply .= "• {$serviceName}: R$ {$memberAmount}\n";
            }
        }

        if ($transactionId) {
            $shortId = strlen($transactionId) > 20 ? substr($transactionId, 0, 10).'...'.substr($transactionId, -6) : $transactionId;
            $reply .= "\n🏦 *Autenticação:* {$shortId}";
        }

        $this->notificationService->sendText($remoteJid, trim($reply), $messageId);
    }

    /**
     * Hypocorisms dictionary for common Brazilian nicknames.
     *
     * @var array<string, array<int, string>>
     */
    private const NICKNAME_MAP = [
        'gabs' => ['gabriel', 'gabriela'],
        'biel' => ['gabriel'],
        'gui' => ['guilherme'],
        'ge' => ['guilherme', 'geraldo', 'geovanna', 'geovana'],
        'rafa' => ['rafael', 'rafaela'],
        'beto' => ['roberto'],
        'dudu' => ['eduardo'],
        'edu' => ['eduardo'],
        'ze' => ['jose'],
        'chico' => ['francisco'],
        'manu' => ['manuela'],
        'ju' => ['juliana', 'julia'],
        'juju' => ['juliana', 'julia'],
        'nat' => ['nathalia', 'natalia'],
        'nati' => ['nathalia', 'natalia'],
        'isa' => ['isabela', 'isadora'],
        'leo' => ['leonardo'],
        'dani' => ['daniel', 'daniela'],
        'lu' => ['lucas', 'luisa', 'luana', 'luiz'],
        'lucca' => ['lucas'],
        'vi' => ['vinicius', 'vitor', 'victoria'],
        'vini' => ['vinicius'],
        'fer' => ['fernando', 'fernanda'],
        'nando' => ['fernando'],
        'ale' => ['alexandre', 'alessandro'],
        'pedro' => ['pedro'],
        'pedrinho' => ['pedro'],
        'joao' => ['joao'],
        'joaozinho' => ['joao'],
        'oscar' => ['oscar'],
        'oscarzinho' => ['oscar'],
    ];

    /**
     * Find matching SubscriptionMember(s) that resolve the receipt amount (single or combo).
     *
     * @return array<SubscriptionMember>|null
     */
    public function matchMembersForPayment(
        string $phoneDigits,
        ?string $payerName,
        string $pushName,
        float $amount,
        string $referenceMonth
    ): ?array {
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
                $resolved = $this->resolveMembersByAmount($byPayer, $amount, $referenceMonth);
                if (! empty($resolved)) {
                    return $resolved;
                }
            }
        }

        // 2. Phone match
        if (! empty($lastDigits)) {
            $byPhone = $candidates->filter(function (SubscriptionMember $m) use ($lastDigits) {
                $memberPhone = $this->extractDigits($m->contact ?? '');

                return ! empty($memberPhone) && str_ends_with($memberPhone, $lastDigits);
            });

            if ($byPhone->isNotEmpty()) {
                $resolved = $this->resolveMembersByAmount($byPhone, $amount, $referenceMonth);
                if (! empty($resolved)) {
                    return $resolved;
                }
            }
        }

        // 3. Fallback to WhatsApp pushName
        if (! empty($pushName)) {
            $byPush = $candidates->filter(fn (SubscriptionMember $m) => $this->namesMatch($m->name, $pushName));

            if ($byPush->isNotEmpty()) {
                $resolved = $this->resolveMembersByAmount($byPush, $amount, $referenceMonth);
                if (! empty($resolved)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * Legacy single-member finder for backward compatibility.
     */
    public function findMember(
        string $phoneDigits,
        ?string $payerName,
        string $pushName,
        float $amount,
        string $referenceMonth
    ): ?SubscriptionMember {
        $members = $this->matchMembersForPayment($phoneDigits, $payerName, $pushName, $amount, $referenceMonth);

        return ! empty($members) ? $members[0] : null;
    }

    /**
     * Resolve single member or multiple members combination matching the amount.
     *
     * @param  Collection<int, SubscriptionMember>  $matchedMembers
     * @return array<SubscriptionMember>|null
     */
    private function resolveMembersByAmount(Collection $matchedMembers, float $amount, string $referenceMonth): ?array
    {
        // 1. Check if a single member's installment matches (within 1.00 tolerance)
        $bySingleAmount = $matchedMembers->filter(function (SubscriptionMember $m) use ($amount) {
            return abs(((float) $m->installment_amount) - $amount) < 1.00;
        });

        if ($bySingleAmount->count() === 1) {
            return [$bySingleAmount->first()];
        }

        if ($bySingleAmount->count() > 1) {
            // Prioritize one pending for this reference month
            $pending = $bySingleAmount->filter(function (SubscriptionMember $m) use ($referenceMonth) {
                $payment = $m->payments->firstWhere('reference_month', $referenceMonth);

                return ! $payment || $payment->status !== SubscriptionPaymentStatus::Paid;
            });

            return [$pending->first() ?: $bySingleAmount->first()];
        }

        // 2. Multi-Subscription Combination (Combo) match
        $pendingMembers = $matchedMembers->filter(function (SubscriptionMember $m) use ($referenceMonth) {
            $payment = $m->payments->firstWhere('reference_month', $referenceMonth);

            return ! $payment || $payment->status !== SubscriptionPaymentStatus::Paid;
        });

        $comboPool = $pendingMembers->isNotEmpty() ? $pendingMembers : $matchedMembers;
        $combo = $this->findSubsetSum($comboPool->values()->all(), $amount);

        if (! empty($combo)) {
            return $combo;
        }

        // If only 1 candidate existed, return it even if amount has slight variance
        if ($matchedMembers->count() === 1) {
            return [$matchedMembers->first()];
        }

        return null;
    }

    /**
     * Find a subset of members whose installment amounts sum to $targetAmount (tolerance 0.10).
     *
     * @param  array<SubscriptionMember>  $members
     * @return array<SubscriptionMember>|null
     */
    private function findSubsetSum(array $members, float $targetAmount, float $tolerance = 0.10): ?array
    {
        $n = count($members);
        if ($n < 2) {
            return null;
        }

        $totalCombos = 1 << $n;
        $bestMatch = null;
        $smallestDiff = $tolerance;

        for ($i = 1; $i < $totalCombos; $i++) {
            // Only consider combinations of 2 or more members
            if (substr_count(decbin($i), '1') < 2) {
                continue;
            }

            $combo = [];
            $sum = 0.0;
            for ($j = 0; $j < $n; $j++) {
                if ($i & (1 << $j)) {
                    $combo[] = $members[$j];
                    $sum += (float) $members[$j]->installment_amount;
                }
            }

            $diff = abs($sum - $targetAmount);
            if ($diff <= $smallestDiff) {
                $smallestDiff = $diff;
                $bestMatch = $combo;
            }
        }

        return $bestMatch;
    }

    /**
     * Check if two names match by first name, initials, single surname, common tokens, or nicknames.
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

        // 1. Check initials / acronyms (e.g. "JV" for "João Victor")
        if ($this->initialsMatch($cleanA, $partsB) || $this->initialsMatch($cleanB, $partsA)) {
            return true;
        }

        // 2. Check if first name matches or nickname matches
        $firstA = $partsA[0];
        $firstB = $partsB[0];
        if ($this->tokensMatch($firstA, $firstB)) {
            return true;
        }

        // 3. Single token match (e.g. Member is registered as single surname "Godoy" or "Pedro")
        if (count($partsA) === 1 && $this->containsToken($partsB, $partsA[0])) {
            return true;
        }
        if (count($partsB) === 1 && $this->containsToken($partsA, $partsB[0])) {
            return true;
        }

        // 4. Check intersection of tokens
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
     * Check if a short string matches the initials of a full name.
     * E.g. "jv" matches ["joao", "victor", "da", "silva"].
     *
     * @param  array<int, string>  $tokens
     */
    private function initialsMatch(string $candidate, array $tokens): bool
    {
        $candidate = str_replace(['.', ' ', '-'], '', mb_strtolower($candidate));
        $len = strlen($candidate);

        if ($len < 2 || $len > 4 || count($tokens) < $len) {
            return false;
        }

        $initials = '';
        for ($i = 0; $i < $len; $i++) {
            $initials .= mb_substr($tokens[$i], 0, 1);
        }

        return $candidate === $initials;
    }

    /**
     * Check if an array of tokens contains a target token (or matches via tokensMatch).
     *
     * @param  array<int, string>  $tokens
     */
    private function containsToken(array $tokens, string $target): bool
    {
        foreach ($tokens as $token) {
            if ($this->tokensMatch($token, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if two name tokens match, accounting for Brazilian hypocorisms, diminutives and prefixes.
     */
    public function tokensMatch(string $tokenA, string $tokenB): bool
    {
        if ($tokenA === $tokenB) {
            return true;
        }

        // Check hypocorism dictionary
        if (isset(self::NICKNAME_MAP[$tokenA]) && in_array($tokenB, self::NICKNAME_MAP[$tokenA], true)) {
            return true;
        }
        if (isset(self::NICKNAME_MAP[$tokenB]) && in_array($tokenA, self::NICKNAME_MAP[$tokenB], true)) {
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
