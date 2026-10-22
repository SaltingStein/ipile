<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dashboard-managed third-party integrations (payment gateways, SMS, email). All field values,
     * secret or not, are stored together in one encrypted `config` column.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique();
            $table->string('type', 20)->index();
            $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_succeeded')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the integrations table; stored credentials are lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
