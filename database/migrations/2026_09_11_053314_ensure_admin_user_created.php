<?php

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $seeder = new UserSeeder;
        $seeder->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::where('email', 'admin@admin.com')->delete();
    }
};
