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
        Schema::create('shopping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->boolean('is_custom_item')->default(false);
            $table->foreignId('stock_category_id')->nullable()->constrained('stock_categories')->nullOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category_name')->nullable();
            $table->string('category_color')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->default('un');
            $table->decimal('min_quantity', 10, 2)->nullable();
            $table->decimal('estimated_price', 10, 2)->default(0);
            $table->decimal('actual_price', 10, 2)->nullable();
            $table->date('expiration_date')->nullable();
            $table->boolean('is_checked')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'is_checked']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopping_items');
    }
};
