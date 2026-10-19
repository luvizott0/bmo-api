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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 10);
            $table->text('description')->nullable();
            $table->string('github_repo')->nullable();
            $table->string('color', 20)->default('#3b82f6');
            $table->unsignedInteger('last_task_number')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'prefix']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
