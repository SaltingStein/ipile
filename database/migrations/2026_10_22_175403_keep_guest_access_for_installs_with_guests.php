<?php

use App\Models\Setting;
use App\Services\Auth\AuthenticationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guest access is now off unless an administrator switches it on (CORE-104). Sites that already have guest accounts
 * and never chose a value keep it on, so their home page and guests behave as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        $chosen = DB::table('settings')->where('key', AuthenticationSettings::GUEST_ACCESS_ENABLED)->exists();

        if (! $chosen && DB::table('users')->where('is_guest', true)->exists()) {
            Setting::store(AuthenticationSettings::GUEST_ACCESS_ENABLED, '1');
        }
    }

    public function down(): void
    {
        // The stored value is a normal admin choice by now, so it is kept.
    }
};
