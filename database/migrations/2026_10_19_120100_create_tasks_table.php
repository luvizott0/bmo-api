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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('task_code', 30);
            $table->unsignedInteger('task_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo');
            $table->string('priority', 20)->default('medium');
            $table->string('branch_name')->nullable();
            $table->string('assignee_type', 20)->default('user');
            $table->json('ai_logs')->nullable();
            $table->integer('order')->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->unique(['project_id', 'task_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
