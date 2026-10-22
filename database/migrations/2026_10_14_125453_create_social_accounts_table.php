<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store OAuth identities (e.g. Google) linked to a local user. One user may link many providers,
     * but each provider identity can only belong to one user.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    /**
     * Drop the linked OAuth identities.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
