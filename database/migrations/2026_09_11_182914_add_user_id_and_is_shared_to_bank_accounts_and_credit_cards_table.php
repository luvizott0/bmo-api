<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('workspace_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_shared')->default(true)->after('is_primary');
        });

        Schema::table('credit_cards', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('workspace_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_shared')->default(true)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('is_shared');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('is_shared');
        });
    }
};
