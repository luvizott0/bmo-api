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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('stock_category_id')->nullable()->constrained('stock_categories')->nullOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->default('un');
            $table->decimal('min_quantity', 10, 2)->nullable();
            $table->decimal('last_price', 10, 2)->nullable();
            $table->decimal('average_price', 10, 2)->nullable();
            $table->date('expiration_date')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->date('last_purchased_at')->nullable();
            $table->boolean('is_regular_expense')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'name']);
            $table->index(['workspace_id', 'stock_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
