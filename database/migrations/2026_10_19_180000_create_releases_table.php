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
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft'); // draft, sandbox, production, cancelled
            $table->timestamp('deployed_to_sandbox_at')->nullable();
            $table->timestamp('deployed_to_production_at')->nullable();
            $table->text('release_notes')->nullable();
            $table->json('logs')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'project_id', 'status']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('release_id')->nullable()->after('project_id')->constrained('releases')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['release_id']);
            $table->dropColumn('release_id');
        });

        Schema::dropIfExists('releases');
    }
};
