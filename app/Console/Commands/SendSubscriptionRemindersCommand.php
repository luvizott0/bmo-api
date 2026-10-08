<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\WhatsAppNotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Signature('financial:send-subscription-reminders {--jid= : Destination WhatsApp JID (group or individual)} {--force : Force sending even if today is not the 9th day of the month}')]
#[Description('Sends a monthly humorous reminder message to WhatsApp on day 9 with shared subscription individual amounts.')]
class SendSubscriptionRemindersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WhatsAppNotificationService $notificationService): int
    {
        $force = (bool) $this->option('force');
        $today = now();

        if (! $force && (int) $today->day !== 9) {
            $this->info("Hoje é dia {$today->day}. O lembrete só é enviado automaticamente no dia 9. Use --force para testar.");

            return Command::SUCCESS;
        }

        $targetJid = $this->option('jid')
            ?: config('services.evolution.subscriptions_reminder_jid')
            ?: Cache::get('last_whatsapp_group_jid');

        if (! $targetJid) {
            $this->warn('Nenhum JID do WhatsApp configurado. Defina WHATSAPP_SUBSCRIPTION_REMINDER_JID no .env ou passe o parâmetro --jid.');
            Log::warning('SendSubscriptionRemindersCommand: Nenhum JID de destino encontrado.');

            return Command::FAILURE;
        }

        $sharedSubscriptions = Subscription::where('is_active', true)
            ->whereHas('members', function ($q) {
                $q->where('is_active', true);
            })
            ->with(['members' => function ($q) {
                $q->where('is_active', true);
            }])
            ->orderBy('service_name')
            ->get();

        if ($sharedSubscriptions->isEmpty()) {
            $this->info('Nenhuma assinatura compartilhada ativa encontrada.');

            return Command::SUCCESS;
        }

        $lines = [];
        $lines[] = '🤠 *Bom dia amigos!*';
        $lines[] = 'Xerife BMO passando aqui para relembrar vocês que o pagamento das assinaturas é amanhã! ⏰';
        $lines[] = '';
        $lines[] = '📺 *Assinaturas da galera (valor individual):*';

        foreach ($sharedSubscriptions as $subscription) {
            $firstMember = $subscription->members->first();
            $individualAmount = $firstMember?->installment_amount;

            if (! $individualAmount || (float) $individualAmount <= 0) {
                $count = max(1, $subscription->members->count());
                $individualAmount = (float) $subscription->total_amount / $count;
            }

            $formatted = number_format((float) $individualAmount, 2, ',', '.');
            $lines[] = "• *{$subscription->service_name}:* R$ {$formatted} cada";
        }

        $lines[] = '';
        $lines[] = '💡 _Lembrando: pode mandar o comprovante do Pix direto aqui no grupo que eu já identifico e dou baixa automaticamente!_ 🤖✨';

        $message = implode("\n", $lines);

        $this->info("Enviando lembrete para {$targetJid}...");
        $sent = $notificationService->sendText($targetJid, $message);

        if ($sent) {
            $this->info('Lembrete enviado com sucesso!');
            Log::info("SendSubscriptionRemindersCommand: Mensagem de lembrete enviada para {$targetJid}");

            return Command::SUCCESS;
        }

        $this->error('Falha ao enviar mensagem pelo WhatsAppNotificationService.');
        Log::error("SendSubscriptionRemindersCommand: Falha ao enviar para {$targetJid}");

        return Command::FAILURE;
    }
}
