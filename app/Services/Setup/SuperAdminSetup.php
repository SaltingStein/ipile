<?php

namespace App\Services\Setup;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * First-run setup: creates the first super admin from the terminal or the key-protected /setup page.
 * Only the hash of the setup key is stored; the plain key is shown once in the terminal or the log.
 */
class SuperAdminSetup
{
    public const ROLE = 'super_admin';

    public const KEY_HASH = 'setup.key_hash';

    /**
     * Setup is needed until at least one account holds the super admin role.
     */
    public function isRequired(): bool
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('name', self::ROLE))->doesntExist();
    }

    /**
     * Generate a new setup key, replacing any earlier one.
     */
    public function issueKey(): string
    {
        $key = implode('-', str_split(Str::upper(Str::random(24)), 6));

        Setting::store(self::KEY_HASH, Hash::make($key));

        return $key;
    }

    /**
     * Issue a key on the first visit to /setup and write it to the log so the server operator can find it.
     */
    public function ensureKeyIssued(): void
    {
        if (Setting::valueOf(self::KEY_HASH) !== null) {
            return;
        }

        $key = $this->issueKey();

        Log::notice("First-run setup key: {$key}. Enter it at ".route('setup.create').' to create the super admin. Run `php artisan ipile:setup-key` for a new one.');
    }

    public function keyMatches(string $key): bool
    {
        $hash = Setting::valueOf(self::KEY_HASH);

        return $hash !== null && Hash::check(Str::upper(trim($key)), $hash);
    }

    /**
     * Create a verified, active account holding the super admin role.
     *
     * @param  array{name: string, email: string, password: string}  $input
     *
     * @throws ValidationException when another super admin was created first.
     */
    public function createSuperAdmin(array $input): User
    {
        return $this->whileRequired(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'is_guest' => false,
                'status' => 'active',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $this->grantRole($user);
        });
    }

    /**
     * Give an existing registered account the super admin role.
     */
    public function promote(User $user): User
    {
        app(RolesAndPermissionsSeeder::class)->run();

        return DB::transaction(fn (): User => $this->grantRole($user));
    }

    /**
     * Run the callback only if setup is still required, so two simultaneous requests cannot both claim the site.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function whileRequired(callable $callback): mixed
    {
        app(RolesAndPermissionsSeeder::class)->run();

        return Cache::lock('setup:super-admin', 10)->block(5, function () use ($callback): mixed {
            if (! $this->isRequired()) {
                throw ValidationException::withMessages(['email' => __('Setup has already been completed.')]);
            }

            return DB::transaction($callback);
        });
    }

    protected function grantRole(User $user): User
    {
        $user->assignRole(self::ROLE);

        // The key is single-use: once a super admin exists it must not unlock anything.
        Setting::query()->where('key', self::KEY_HASH)->first()?->delete();

        return $user;
    }
}
