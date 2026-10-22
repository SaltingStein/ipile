<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the draft verification/2FA columns with the shape used by the authentication module.
     *
     * Email verification now uses signed URLs (no stored token), and two-factor state is tracked by
     * an encrypted secret, encrypted recovery codes, a confirmation timestamp and the last accepted
     * TOTP window (replay protection).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verification_token', 'two_factor_enabled']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->change();
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_used_window')->nullable()->after('two_factor_confirmed_at');
        });
    }

    /**
     * Restore the draft columns. Two-factor secrets written after this migration are encrypted
     * and will not be usable by the draft implementation.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_window']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_secret')->nullable()->change();
            $table->string('email_verification_token')->nullable()->after('email_verified_at');
            $table->boolean('two_factor_enabled')->default(false)->after('two_factor_secret');
        });
    }
};
