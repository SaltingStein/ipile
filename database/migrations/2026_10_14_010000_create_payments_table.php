<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the payments ledger used to track gateway transactions for each user.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('NGN');
            $table->string('reference')->unique();
            $table->string('provider');
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    /**
     * Drop the payment ledger when the migration is rolled back.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
