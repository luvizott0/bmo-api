<?php

namespace App\Console\Commands;

use App\Services\EmailInvoiceSyncService;
use Illuminate\Console\Command;

class AuthOutlookEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:auth-outlook {--poll : Wait and poll until user authorizes the device code}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Authorize Outlook IMAP access via Microsoft OAuth2 Device Code flow';

    public function handle(EmailInvoiceSyncService $syncService): int
    {
        $start = $syncService->startOutlookDeviceAuth();

        if (! ($start['success'] ?? false)) {
            $this->error($start['error'] ?? 'Failed to start device code flow');

            return self::FAILURE;
        }

        $this->info('==========================================================');
        $this->info('AUTORIZAÇÃO DO OUTLOOK (MICROSOFT OAUTH2)');
        $this->info('==========================================================');
        $this->line('1. Acesse: '.$start['verification_uri']);
        $this->line('2. Digite o código: '.$start['user_code']);
        $this->info('==========================================================');

        if (! $this->option('poll')) {
            return self::SUCCESS;
        }

        $this->info('Aguardando autorização no navegador...');
        $interval = max(5, (int) ($start['interval'] ?? 5));
        $maxAttempts = (int) ceil(((int) ($start['expires_in'] ?? 900)) / $interval);

        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep($interval);
            $poll = $syncService->pollOutlookDeviceAuth($start['device_code'] ?? null);

            if ($poll['status'] === 'authorized') {
                $this->info('✅ '.$poll['message']);

                return self::SUCCESS;
            }

            if ($poll['status'] !== 'pending') {
                $this->error('Erro na autorização: '.($poll['message'] ?? 'Desconhecido'));

                return self::FAILURE;
            }
        }

        $this->error('Tempo limite expirado.');

        return self::FAILURE;
    }
}
