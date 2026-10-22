<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend the base user records with account lifecycle fields that support
     * registration, email verification, team membership, and 2FA toggles.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('nickname');
            $table->string('email_verification_token')->nullable()->after('email_verified_at');
            $table->string('two_factor_secret')->nullable()->after('email_verification_token');
            $table->boolean('two_factor_enabled')->default(false)->after('two_factor_secret');
            $table->unsignedBigInteger('team_id')->nullable()->after('two_factor_enabled');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('owner_id');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Revert the account lifecycle shape and remove the team association tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'email_verification_token',
                'two_factor_secret',
                'two_factor_enabled',
                'team_id',
            ]);
        });
    }
};
