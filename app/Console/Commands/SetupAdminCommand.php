<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('admin:setup')]
#[Description('Garante a criação ou redefinição do usuário administrador no banco de dados')]
class SetupAdminCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Configurando usuário administrador...');

        $seeder = new UserSeeder;
        $seeder->run();

        $admin = User::where('email', 'admin@admin.com')->first();

        $this->info('Usuário administrador pronto para uso!');
        $this->table(
            ['ID', 'Nome', 'E-mail', 'É Admin?', 'Workspace'],
            [
                [
                    $admin?->id,
                    $admin?->name,
                    $admin?->email,
                    $admin?->is_admin ? 'Sim' : 'Não',
                    $admin?->personalWorkspace()?->name ?? 'Nenhum',
                ],
            ]
        );

        return self::SUCCESS;
    }
}
