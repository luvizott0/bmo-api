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
        Schema::table('subscription_members', function (Blueprint $table) {
            $table->foreignId('friend_id')->nullable()->after('user_id')->constrained('friends')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_members', function (Blueprint $table) {
            $table->dropForeign(['friend_id']);
            $table->dropColumn('friend_id');
        });
    }
};
