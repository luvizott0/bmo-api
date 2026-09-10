<?php

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'admin',
                'password' => Hash::make('Zephryne2301*'),
                'is_admin' => true,
            ]
        );

        $workspace = Workspace::firstOrCreate(
            ['owner_id' => $admin->id, 'is_personal' => true],
            ['name' => 'Admin Workspace']
        );

        if (! $workspace->members()->where('user_id', $admin->id)->exists()) {
            $workspace->members()->attach($admin->id, [
                'role' => WorkspaceRole::Owner->value,
            ]);
        }

        CategorySeeder::seedForWorkspace($workspace);
        StockCategorySeeder::seedForWorkspace($workspace);
    }
}
