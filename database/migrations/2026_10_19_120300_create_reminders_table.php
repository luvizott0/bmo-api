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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->dateTime('due_at');
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('category', 30)->default('routine'); // routine, house, personal, etc.
            $table->string('recurrence', 20)->default('none'); // none, daily, weekly, monthly
            $table->boolean('notify_whatsapp')->default(true);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_completed', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
