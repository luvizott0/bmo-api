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
            $table->string('barcode')->nullable()->after('notes');
            $table->text('pix_code')->nullable()->after('barcode');
            $table->string('external_message_id')->nullable()->index()->after('pix_code');
        });

        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['barcode', 'pix_code', 'external_message_id']);
        });

        Schema::dropIfExists('integration_settings');
    }
};
