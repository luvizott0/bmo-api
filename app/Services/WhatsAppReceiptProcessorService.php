<?php

namespace App\Services;

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\FixedBill;
use App\Models\Friend;
use App\Models\SubscriptionMember;
use App\Models\SubscriptionPayment;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WhatsAppReceiptProcessorService
{
    public function __construct(
        private readonly ReceiptExtractorService $extractorService,
        private readonly WhatsAppNotificationService $notificationService,
        private readonly SubscriptionService $subscriptionService,
        private readonly ?EmailInvoiceSyncService $emailInvoiceSyncService = null
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

        if (str_ends_with($remoteJid, '@g.us')) {
            cache()->forever('last_whatsapp_group_jid', $remoteJid);
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
            $textContent = $this->extractTextMessage($messageObj);
            if ($textContent !== '') {
                $this->handleTextCommand($remoteJid, $textContent, $messageId);
            }

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

        $caption = $this->extractCaption($messageObj);
        $financialGroupJid = config('services.evolution.financial_group_jid');

        // If sent in the financial group (or caption mentions fatura/boleto), check if it's a utility bill invoice PDF first
        if ($remoteJid === $financialGroupJid || preg_match('/\b(fatura|boleto|cpfl|alares)\b/iu', $caption.' '.($filename ?? ''))) {
            if ($this->tryProcessUtilityInvoiceMedia($remoteJid, $base64, $mimetype, $filename, $caption, $messageId)) {
                return;
            }
        }

        // Extract receipt data via Python extractor
        $extracted = $this->extractorService->extractFromBase64($base64, $mimetype, $filename);

        if (! ($extracted['success'] ?? false) || empty($extracted['amount'])) {
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

        // If in the financial group, check if this receipt pays a pending FixedBill of the house
        if ($remoteJid === $financialGroupJid) {
            if ($this->tryProcessFixedBillPaymentReceipt($remoteJid, $amount, $paymentDate, $extracted, $messageId)) {
                return;
            }
        }

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
        // Query active members with friend relationship
        $query = SubscriptionMember::where('is_active', true)
            ->whereHas('subscription', function ($q) {
                $q->where('is_active', true);
            })
            ->with(['subscription.workspace.owner', 'payments', 'friend']);

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // 1. Payer name match from receipt (highest priority when forwarded or sent in group)
        if (! empty($payerName)) {
            $byPayer = $candidates->filter(function (SubscriptionMember $m) use ($payerName) {
                if ($this->namesMatch($m->name, $payerName)) {
                    return true;
                }
                if ($m->friend && $this->namesMatch($m->friend->name, $payerName)) {
                    return true;
                }

                return false;
            });

            if ($byPayer->isNotEmpty()) {
                $resolved = $this->resolveMembersByAmount($byPayer, $amount, $referenceMonth);
                if (! empty($resolved)) {
                    return $resolved;
                }
            }
        }

        // 2. Phone match (via direct member contact or linked Friend phone)
        if (! empty($phoneDigits)) {
            // A. Check members directly by contact or linked friend phone
            $byPhone = $candidates->filter(function (SubscriptionMember $m) use ($phoneDigits) {
                if ($this->phonesMatch($m->contact, $phoneDigits)) {
                    return true;
                }
                if ($m->friend && $this->phonesMatch($m->friend->phone, $phoneDigits)) {
                    return true;
                }

                return false;
            });

            if ($byPhone->isNotEmpty()) {
                $resolved = $this->resolveMembersByAmount($byPhone, $amount, $referenceMonth);
                if (! empty($resolved)) {
                    return $resolved;
                }
            }

            // B. Check workspace Friend registry by phone, matching member by name or friend_id
            $matchedFriend = Friend::whereHas('workspace.subscriptions', function ($q) {
                $q->where('is_active', true);
            })->get()->first(fn (Friend $f) => $this->phonesMatch($f->phone, $phoneDigits));

            if ($matchedFriend) {
                $byFriend = $candidates->filter(function (SubscriptionMember $m) use ($matchedFriend) {
                    return $m->friend_id === $matchedFriend->id || $this->namesMatch($m->name, $matchedFriend->name);
                });

                if ($byFriend->isNotEmpty()) {
                    $resolved = $this->resolveMembersByAmount($byFriend, $amount, $referenceMonth);
                    if (! empty($resolved)) {
                        return $resolved;
                    }
                }
            }
        }

        // 3. Fallback to WhatsApp pushName
        if (! empty($pushName)) {
            $byPush = $candidates->filter(function (SubscriptionMember $m) use ($pushName) {
                if ($this->namesMatch($m->name, $pushName)) {
                    return true;
                }
                if ($m->friend && $this->namesMatch($m->friend->name, $pushName)) {
                    return true;
                }

                return false;
            });

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
     * Compare two phone numbers considering Brazilian country code, DDD and 8/9 digits.
     */
    public function phonesMatch(?string $phoneA, ?string $phoneB): bool
    {
        $digitsA = $this->extractDigits($phoneA);
        $digitsB = $this->extractDigits($phoneB);

        if (empty($digitsA) || empty($digitsB)) {
            return false;
        }

        if ($digitsA === $digitsB) {
            return true;
        }

        $last8A = strlen($digitsA) >= 8 ? substr($digitsA, -8) : $digitsA;
        $last8B = strlen($digitsB) >= 8 ? substr($digitsB, -8) : $digitsB;

        return $last8A === $last8B;
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

    public const AMOUNT_TOLERANCE = 0.99;

    /**
     * Resolve single member or multiple members combination matching the amount within tolerance.
     *
     * @param  Collection<int, SubscriptionMember>  $matchedMembers
     * @return array<SubscriptionMember>|null
     */
    private function resolveMembersByAmount(Collection $matchedMembers, float $amount, string $referenceMonth): ?array
    {
        $tolerance = self::AMOUNT_TOLERANCE;

        // Separate members pending for this reference month
        $pendingMembers = $matchedMembers->filter(function (SubscriptionMember $m) use ($referenceMonth) {
            $payment = $m->payments->firstWhere('reference_month', $referenceMonth);

            return ! $payment || $payment->status !== SubscriptionPaymentStatus::Paid;
        });

        // 1. Check pending members first
        if ($pendingMembers->isNotEmpty()) {
            $pendingSingles = $pendingMembers->filter(function (SubscriptionMember $m) use ($amount, $tolerance) {
                return round(abs(((float) $m->installment_amount) - $amount), 2) <= $tolerance;
            });

            $bestPendingSingle = $pendingSingles->isNotEmpty()
                ? $pendingSingles->sortBy(fn (SubscriptionMember $m) => round(abs(((float) $m->installment_amount) - $amount), 2))->first()
                : null;

            $pendingCombo = $pendingMembers->count() >= 2
                ? $this->findSubsetSum($pendingMembers->values()->all(), $amount, $tolerance)
                : null;

            if ($bestPendingSingle && $pendingCombo) {
                $singleDiff = round(abs(((float) $bestPendingSingle->installment_amount) - $amount), 2);
                $comboSum = collect($pendingCombo)->sum(fn ($m) => (float) $m->installment_amount);
                $comboDiff = round(abs($comboSum - $amount), 2);

                return ($comboDiff < $singleDiff) ? $pendingCombo : [$bestPendingSingle];
            }

            if ($bestPendingSingle) {
                return [$bestPendingSingle];
            }

            if ($pendingCombo) {
                return $pendingCombo;
            }
        }

        // 2. Fallback to all matched members (if none pending or pending members didn't match within tolerance)
        $allSingles = $matchedMembers->filter(function (SubscriptionMember $m) use ($amount, $tolerance) {
            return round(abs(((float) $m->installment_amount) - $amount), 2) <= $tolerance;
        });

        $bestAllSingle = $allSingles->isNotEmpty()
            ? $allSingles->sortBy(fn (SubscriptionMember $m) => round(abs(((float) $m->installment_amount) - $amount), 2))->first()
            : null;

        $allCombo = $matchedMembers->count() >= 2
            ? $this->findSubsetSum($matchedMembers->values()->all(), $amount, $tolerance)
            : null;

        if ($bestAllSingle && $allCombo) {
            $singleDiff = round(abs(((float) $bestAllSingle->installment_amount) - $amount), 2);
            $comboSum = collect($allCombo)->sum(fn ($m) => (float) $m->installment_amount);
            $comboDiff = round(abs($comboSum - $amount), 2);

            return ($comboDiff < $singleDiff) ? $allCombo : [$bestAllSingle];
        }

        if ($bestAllSingle) {
            return [$bestAllSingle];
        }

        if ($allCombo) {
            return $allCombo;
        }

        return null;
    }

    /**
     * Find a subset of members whose installment amounts sum to $targetAmount (tolerance 0.99).
     *
     * @param  array<SubscriptionMember>  $members
     * @return array<SubscriptionMember>|null
     */
    private function findSubsetSum(array $members, float $targetAmount, float $tolerance = self::AMOUNT_TOLERANCE): ?array
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

            $diff = round(abs($sum - $targetAmount), 2);
            if ($bestMatch === null && $diff <= $smallestDiff) {
                $smallestDiff = $diff;
                $bestMatch = $combo;
            } elseif ($diff < $smallestDiff) {
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

    /**
     * Extract plain text from a WhatsApp conversation or extendedTextMessage.
     *
     * @param  array<string, mixed>  $messageObj
     */
    private function extractTextMessage(array $messageObj): string
    {
        $text = $messageObj['conversation']
            ?? ($messageObj['extendedTextMessage']['text'] ?? '');

        return trim((string) $text);
    }

    /**
     * Handle interactive text commands in WhatsApp (such as listing pending bills or syncing emails).
     */
    private function handleTextCommand(string $remoteJid, string $text, ?string $messageId = null): void
    {
        $normalized = mb_strtolower(trim($text));

        // 1. Command: List bills to pay ("me mostre as contas a pagar", "contas a pagar", "/contas", etc.)
        if (
            preg_match('/\b(contas?\s+a\s+pagar|me\s+mostre\s+as\s+contas|mostrar\s+contas|contas?\s+pendentes?|contas?\s+da\s+casa|contas?\s+do\s+m[eê]s|quais\s+as\s+contas|listar?\s+contas)\b/iu', $normalized)
            || preg_match('/^\/?(contas|boletos|faturas|pagar)$/iu', $normalized)
        ) {
            $this->sendPendingBillsList($remoteJid, $messageId);

            return;
        }

        // 2. Command: Sync emails on demand ("atualizar contas", "checar emails", "/emails", "/sincronizar")
        if (
            preg_match('/\b(sincronizar\s+contas|atualizar\s+contas|checar\s+e?-?mails?|verificar\s+e?-?mails?|buscar\s+contas\s+no\s+e?-?mail)\b/iu', $normalized)
            || preg_match('/^\/?(sync|emails|sincronizar)$/iu', $normalized)
        ) {
            $this->handleEmailSyncCommand($remoteJid, $messageId);

            return;
        }

        // 3. Command: Help ("/ajuda", "/comandos", "bmo ajuda")
        if (preg_match('/^(\/ajuda|\/comandos|bmo\s+ajuda)$/iu', $normalized)) {
            $helpText = implode("\n", [
                '🤖 *Comandos Financeiros do BMO:*',
                '',
                '• *"me mostre as contas a pagar"* (ou */contas*) — Lista todas as contas da casa do mês atual, valores e envia o Pix Copia e Cola / Código de Barras.',
                '• *"atualizar contas"* (ou */emails*) — Verifica a caixa de entrada do Outlook atrás de novas faturas da CPFL e Alares.',
                '• *Enviar PDF da Fatura* — Atualiza automaticamente o valor da conta do mês e extrai o Pix/Código de Barras.',
                '• *Enviar Comprovante de Pagamento* — Dá baixa automática na conta correspondente.',
            ]);
            $this->notificationService->sendText($remoteJid, $helpText, $messageId);
        }
    }

    /**
     * Format and send the list of current month's FixedBills and their Pix/Barcodes.
     */
    public function sendPendingBillsList(string $remoteJid, ?string $messageId = null): void
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $monthLabel = $now->format('m/Y');

        $bills = FixedBill::where('is_active', true)
            ->with(['transactions' => function ($query) use ($startOfMonth, $endOfMonth) {
                $query->whereBetween('occurred_at', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                    ->orderByDesc('occurred_at');
            }])
            ->orderBy('due_day')
            ->get();

        if ($bills->isEmpty()) {
            $this->notificationService->sendText(
                $remoteJid,
                '📋 Nenhuma conta fixa ativa cadastrada no BMO no momento.',
                $messageId
            );

            return;
        }

        $pendingLines = [];
        $paidLines = [];
        $codesToSend = [];
        $totalPending = 0.0;
        $idx = 1;

        foreach ($bills as $bill) {
            /** @var Transaction|null $paidTx */
            $paidTx = $bill->transactions->first(fn (Transaction $t) => $t->status === TransactionStatus::Paid);
            /** @var Transaction|null $invoiceTx */
            $invoiceTx = $paidTx ?? $bill->transactions->first();

            $amount = $invoiceTx ? (float) $invoiceTx->amount : (float) $bill->estimated_amount;
            $formattedAmount = number_format($amount, 2, ',', '.');

            $dueDateStr = $invoiceTx
                ? $invoiceTx->occurred_at->format('d/m/Y')
                : sprintf('%02d/%s', min((int) $bill->due_day, $endOfMonth->day), $monthLabel);

            if ($paidTx) {
                $paidLines[] = "✅ *{$bill->name}* — R$ {$formattedAmount} _(Pago em {$paidTx->occurred_at->format('d/m')})_";
            } else {
                $totalPending += $amount;
                $codeBadge = '';
                if ($invoiceTx?->pix_code) {
                    $codeBadge = ' 💠 _Pix disponível_';
                    $codesToSend[] = [
                        'bill_name' => $bill->name,
                        'amount' => $formattedAmount,
                        'type' => 'Pix Copia e Cola',
                        'code' => $invoiceTx->pix_code,
                    ];
                } elseif ($invoiceTx?->barcode) {
                    $codeBadge = ' 🔢 _Cód. Barras disponível_';
                    $codesToSend[] = [
                        'bill_name' => $bill->name,
                        'amount' => $formattedAmount,
                        'type' => 'Código de Barras',
                        'code' => $invoiceTx->barcode,
                    ];
                } elseif (! $invoiceTx) {
                    $codeBadge = ' _(Valor estimado)_';
                }

                $pendingLines[] = "{$idx}. *{$bill->name}* — R$ {$formattedAmount} (Venc: {$dueDateStr}){$codeBadge}";
                $idx++;
            }
        }

        $messageParts = [
            "📋 *Contas da Casa — {$monthLabel}*",
            '',
        ];

        if (! empty($pendingLines)) {
            $messageParts[] = '⏳ *CONTAS A PAGAR:*';
            foreach ($pendingLines as $line) {
                $messageParts[] = $line;
            }
            $formattedTotal = number_format($totalPending, 2, ',', '.');
            $messageParts[] = '';
            $messageParts[] = "💰 *Total pendente:* R$ {$formattedTotal}";
        } else {
            $messageParts[] = '🎉 *Todas as contas deste mês já estão pagas!*';
        }

        if (! empty($paidLines)) {
            $messageParts[] = '';
            $messageParts[] = '✔️ *JÁ PAGAS ESTE MÊS:*';
            foreach ($paidLines as $line) {
                $messageParts[] = $line;
            }
        }

        $this->notificationService->sendText($remoteJid, implode("\n", $messageParts), $messageId);

        // Send individual copy-paste blocks for each pending bill that has Pix or Barcode
        foreach ($codesToSend as $item) {
            $this->notificationService->sendText(
                $remoteJid,
                "👇 *{$item['type']} — {$item['bill_name']} (R$ {$item['amount']}):*"
            );
            $this->notificationService->sendText($remoteJid, $item['code']);
        }
    }

    /**
     * Trigger email synchronization when requested via WhatsApp.
     */
    private function handleEmailSyncCommand(string $remoteJid, ?string $messageId = null): void
    {
        $syncService = $this->resolveEmailSyncService();
        $result = $syncService->syncFromEmail(notifyWhatsApp: true);

        if (! $result['success']) {
            $this->notificationService->sendText(
                $remoteJid,
                "⚠️ Não foi possível sincronizar a caixa de e-mail agora: {$result['error']}",
                $messageId
            );

            return;
        }

        if ($result['synced'] === 0) {
            $this->notificationService->sendText(
                $remoteJid,
                "📬 Verifiquei os últimos {$result['scanned']} e-mails da caixa de entrada e nenhuma nova fatura pendente da CPFL ou Alares foi encontrada.",
                $messageId
            );
        } else {
            $this->notificationService->sendText(
                $remoteJid,
                "✅ Sincronização concluída! {$result['synced']} fatura(s) atualizada(s) a partir do e-mail.",
                $messageId
            );
        }
    }

    /**
     * Check if a media file sent in the financial group is an invoice (fatura CPFL/Alares) and sync it.
     */
    private function tryProcessUtilityInvoiceMedia(
        string $remoteJid,
        string $base64,
        string $mimetype,
        ?string $filename,
        string $caption,
        ?string $messageId
    ): bool {
        $invoiceData = $this->extractorService->extractInvoice(
            base64: $base64,
            mimetype: $mimetype,
            filename: $filename,
            text: $caption
        );

        if (! ($invoiceData['success'] ?? false) || empty($invoiceData['amount']) || empty($invoiceData['provider'])) {
            return false;
        }

        $hasInvoiceCode = ! empty($invoiceData['pix_code']) || ! empty($invoiceData['barcode']);
        $hasInvoiceKeyword = (bool) preg_match('/\b(fatura|boleto|conta|vencimento)\b/iu', $caption.' '.($filename ?? ''));

        if (! $hasInvoiceCode && ! $hasInvoiceKeyword) {
            return false;
        }

        $invoiceData['external_message_id'] = $messageId ? "wa-invoice-{$messageId}" : null;

        $synced = $this->resolveEmailSyncService()->syncInvoicePayload(
            invoiceData: $invoiceData,
            notifyWhatsApp: true,
            replyJid: $remoteJid
        );

        return $synced !== null;
    }

    /**
     * Check if a payment receipt sent in the financial group matches a pending FixedBill of the current month.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function tryProcessFixedBillPaymentReceipt(
        string $remoteJid,
        float $amount,
        string $paymentDate,
        array $extracted,
        ?string $messageId
    ): bool {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        $activeBills = FixedBill::with(['workspace', 'transactions' => function ($query) use ($startOfMonth, $endOfMonth) {
            $query->whereBetween('occurred_at', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                ->orderByDesc('occurred_at');
        }])->where('is_active', true)->get();

        $recipientLower = mb_strtolower((string) ($extracted['recipient_name'] ?? ''));

        $matchedBill = null;
        $pendingTx = null;

        foreach ($activeBills as $bill) {
            $alreadyPaid = $bill->transactions->first(fn (Transaction $t) => $t->status === TransactionStatus::Paid);
            if ($alreadyPaid) {
                continue;
            }

            /** @var Transaction|null $candidateTx */
            $candidateTx = $bill->transactions->first();
            $targetAmount = $candidateTx ? (float) $candidateTx->amount : (float) $bill->estimated_amount;

            // Match by amount (within R$ 0.99 tolerance) or by recipient name (CPFL / Alares)
            $amountMatches = abs($targetAmount - $amount) <= 0.99;
            $billNameLower = mb_strtolower($bill->name);
            $recipientMatches = ($recipientLower !== '' && (
                (str_contains($recipientLower, 'cpfl') && (str_contains($billNameLower, 'cpfl') || str_contains($billNameLower, 'luz') || str_contains($billNameLower, 'energia')))
                || (str_contains($recipientLower, 'alares') && (str_contains($billNameLower, 'alares') || str_contains($billNameLower, 'internet')))
            ));

            if ($amountMatches || ($recipientMatches && abs($targetAmount - $amount) <= 25.00)) {
                $matchedBill = $bill;
                $pendingTx = $candidateTx;
                break;
            }
        }

        if (! $matchedBill) {
            return false;
        }

        $txService = app(TransactionService::class);

        if ($pendingTx) {
            $txService->update($pendingTx, [
                'status' => TransactionStatus::Paid,
                'amount' => $amount,
                'occurred_at' => $paymentDate,
                'bank_account_id' => $pendingTx->bank_account_id ?: $matchedBill->preferred_bank_account_id,
            ]);
        } else {
            $txService->create([
                'workspace_id' => $matchedBill->workspace_id,
                'created_by_user_id' => $matchedBill->workspace?->owner_id ?? 1,
                'category_id' => $matchedBill->category_id,
                'bank_account_id' => $matchedBill->preferred_bank_account_id,
                'fixed_bill_id' => $matchedBill->id,
                'type' => $matchedBill->type ?? TransactionType::Expense,
                'status' => TransactionStatus::Paid,
                'description' => "Pagamento {$matchedBill->name}",
                'amount' => $amount,
                'occurred_at' => $paymentDate,
            ]);
        }

        $formattedAmount = number_format($amount, 2, ',', '.');
        $this->notificationService->sendText(
            $remoteJid,
            "✅ *Pagamento registrado no BMO!*\n\n🏠 *Conta:* {$matchedBill->name}\n💰 *Valor pago:* R$ {$formattedAmount}\n📅 *Data:* ".Carbon::parse($paymentDate)->format('d/m/Y'),
            $messageId
        );

        return true;
    }

    private function resolveEmailSyncService(): EmailInvoiceSyncService
    {
        return $this->emailInvoiceSyncService ?? app(EmailInvoiceSyncService::class);
    }
}
