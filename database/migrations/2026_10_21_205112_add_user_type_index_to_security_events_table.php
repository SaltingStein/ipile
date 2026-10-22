<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets configurable signals (velocity, value history) count one account's recent events of given types with a
 * single indexed query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->index(['user_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type', 'occurred_at']);
        });
    }
};
