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
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_member_id')->constrained('subscription_members')->cascadeOnDelete();
            $table->string('reference_month', 7); // YYYY-MM
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('pending');
            $table->date('payment_date')->nullable();
            $table->timestamps();

            $table->unique(['subscription_member_id', 'reference_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
