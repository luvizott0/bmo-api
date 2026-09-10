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
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedSmallInteger('installment_number')->nullable()->after('amount');
            $table->unsignedSmallInteger('total_installments')->nullable()->after('installment_number');
            $table->string('installment_group_id', 36)->nullable()->after('total_installments');
            $table->index('installment_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['installment_group_id']);
            $table->dropColumn(['installment_number', 'total_installments', 'installment_group_id']);
        });
    }
};
