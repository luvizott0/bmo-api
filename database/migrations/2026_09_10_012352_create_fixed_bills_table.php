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
        Schema::create('fixed_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('expense');
            $table->decimal('estimated_amount', 14, 2);
            $table->unsignedTinyInteger('due_day');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('preferred_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_reminder_active')->default(true);
            $table->unsignedTinyInteger('reminder_days_before')->default(3);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_bills');
    }
};
