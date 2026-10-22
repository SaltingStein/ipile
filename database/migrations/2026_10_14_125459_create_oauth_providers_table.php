<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hold OAuth client credentials managed from the admin dashboard instead of `.env`.
     */
    public function up(): void
    {
        Schema::create('oauth_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Drop the dashboard-managed OAuth providers.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_providers');
    }
};
