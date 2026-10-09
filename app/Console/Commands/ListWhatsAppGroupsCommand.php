<?php

namespace App\Console\Commands;

use App\Services\WhatsAppNotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:list-groups')]
#[Description('Lists WhatsApp groups that the bot is participating in, displaying their JIDs for configuration.')]
class ListWhatsAppGroupsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WhatsAppNotificationService $notificationService): int
    {
        $this->info('Consultando grupos na Evolution API...');

        $groups = $notificationService->fetchAllGroups();

        if (empty($groups)) {
            $this->warn('Nenhum grupo encontrado ou a instância do WhatsApp está desconectada.');
            $this->line('Verifique se o bot está conectado ao WhatsApp no Evolution Manager (http://<IP>:8083).');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                'Nome do Grupo' => $group['subject'] ?? 'Sem nome',
                'JID (Copiar para o .env)' => $group['id'] ?? 'N/A',
            ];
        }

        $this->table(['Nome do Grupo', 'JID (Copiar para o .env)'], $rows);

        $this->info('💡 Para configurar o envio automático das assinaturas, adicione no arquivo .env:');
        $this->line('WHATSAPP_SUBSCRIPTION_REMINDER_JID=' . ($groups[0]['id'] ?? 'ID_DO_GRUPO@g.us'));

        return Command::SUCCESS;
    }
}
