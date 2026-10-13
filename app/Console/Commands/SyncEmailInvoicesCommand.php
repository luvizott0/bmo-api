<?php

namespace App\Console\Commands;

use App\Services\EmailInvoiceSyncService;
use Illuminate\Console\Command;

class SyncEmailInvoicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bills:sync-emails {--no-notify : Do not send WhatsApp notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize utility bills (CPFL, Alares) from the configured Outlook IMAP inbox';

    public function handle(EmailInvoiceSyncService $syncService): int
    {
        $notify = ! $this->option('no-notify');
        $this->info('Iniciando leitura de faturas na caixa de entrada do e-mail...');

        $result = $syncService->syncFromEmail($notify);

        if (! $result['success']) {
            $this->error('Falha ao sincronizar e-mails: '.($result['error'] ?? 'Erro desconhecido'));

            return self::FAILURE;
        }

        $this->info("E-mails verificados: {$result['scanned']} | Faturas sincronizadas: {$result['synced']}");

        foreach ($result['items'] as $item) {
            $this->line(sprintf(
                ' - [%s] %s (%s): R$ %s (Venc: %s)',
                $item['provider'],
                $item['fixed_bill_name'],
                $item['reference_month'],
                number_format((float) $item['amount'], 2, ',', '.'),
                $item['due_date']
            ));
        }

        return self::SUCCESS;
    }
}
