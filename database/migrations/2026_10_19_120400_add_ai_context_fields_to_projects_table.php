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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('local_path')->nullable()->after('github_repo');
            $table->string('base_branch', 50)->default('develop')->after('local_path');
            $table->string('test_command')->nullable()->after('base_branch');
            $table->string('instructions_file')->default('AGENTS.md')->after('test_command');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['local_path', 'base_branch', 'test_command', 'instructions_file']);
        });
    }
};
