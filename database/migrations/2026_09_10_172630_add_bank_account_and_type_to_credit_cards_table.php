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
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('workspace_id')->constrained('bank_accounts')->nullOnDelete();
            $table->string('type')->default('credit')->after('name');
            $table->decimal('daily_limit', 14, 2)->nullable()->after('total_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn(['bank_account_id', 'type', 'daily_limit']);
        });
    }
};
