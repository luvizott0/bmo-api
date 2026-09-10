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
        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->string('color_hex', 20)->nullable()->default('#3b82f6')->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->dropColumn('color_hex');
        });
    }
};
