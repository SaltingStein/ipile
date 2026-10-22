<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log of outgoing SMS so delivery can be audited and receipts matched to messages.
     */
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('to', 20);
            $table->string('sender', 20);
            $table->text('body');
            $table->string('status', 20)->default('queued')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the SMS log.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
