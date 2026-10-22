<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\ApiTokens;
use App\Services\Auth\AuthenticationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTokenLifetimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_tokens_expire_after_the_default_lifetime(): void
    {
        $this->login(User::factory()->create());

        $expiresAt = PersonalAccessToken::query()->sole()->expires_at;
        $this->assertTrue($expiresAt->between(now()->addDays(365)->subMinute(), now()->addDays(365)->addMinute()));
    }

    public function test_a_zero_lifetime_creates_tokens_that_never_expire(): void
    {
        Setting::store(AuthenticationSettings::API_TOKEN_LIFETIME_DAYS, '0');

        $this->login(User::factory()->create());

        $this->assertNull(PersonalAccessToken::query()->sole()->expires_at);
    }

    public function test_a_requested_expiry_cannot_exceed_the_lifetime(): void
    {
        Setting::store(AuthenticationSettings::API_TOKEN_LIFETIME_DAYS, '30');

        $token = app(ApiTokens::class)->create(User::factory()->create(), 'Script', 365)->accessToken;

        $this->assertTrue($token->expires_at->lessThanOrEqualTo(now()->addDays(30)));
    }

    public function test_a_token_unused_for_longer_than_the_idle_timeout_is_refused(): void
    {
        $plainText = $this->tokenLastUsed(now()->subDays(61));

        $this->getJson(route('api.v1.auth.user'), ['Authorization' => 'Bearer '.$plainText])->assertUnauthorized();
    }

    public function test_a_token_used_within_the_idle_timeout_still_works(): void
    {
        $plainText = $this->tokenLastUsed(now()->subDays(59));

        $this->getJson(route('api.v1.auth.user'), ['Authorization' => 'Bearer '.$plainText])->assertOk();
    }

    public function test_a_zero_idle_timeout_keeps_old_tokens_working(): void
    {
        Setting::store(AuthenticationSettings::API_TOKEN_IDLE_DAYS, '0');
        $plainText = $this->tokenLastUsed(now()->subYears(2));

        $this->getJson(route('api.v1.auth.user'), ['Authorization' => 'Bearer '.$plainText])->assertOk();
    }

    public function test_pruning_deletes_only_idle_tokens(): void
    {
        $this->tokenLastUsed(now()->subDays(90));
        $this->tokenLastUsed(now()->subDays(5));
        $neverUsed = app(ApiTokens::class)->create(User::factory()->create(), 'Old, never used')->accessToken;
        $neverUsed->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->artisan('api-tokens:prune-idle')->assertSuccessful();

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_admins_set_the_lifetime_and_idle_timeout_in_the_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        $payload = ['registration_enabled' => true, 'trusted_device_days' => 30, 'two_factor_required_roles' => []];

        $this->actingAs($manager)->put(route('admin.authentication.update'), [...$payload, 'api_token_lifetime_days' => 90, 'api_token_idle_days' => 0])
            ->assertSessionHasNoErrors();

        $settings = app(AuthenticationSettings::class);
        $this->assertSame(90, $settings->apiTokenLifetimeDays());
        $this->assertSame(0, $settings->apiTokenIdleDays());

        $this->actingAs($manager)->put(route('admin.authentication.update'), [...$payload, 'api_token_idle_days' => 5000])
            ->assertSessionHasErrors('api_token_idle_days');
    }

    private function login(User $user): void
    {
        $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel'])->assertOk();
    }

    private function tokenLastUsed(\DateTimeInterface $lastUsedAt): string
    {
        $token = app(ApiTokens::class)->create(User::factory()->create(), 'Pixel');
        $token->accessToken->forceFill(['last_used_at' => $lastUsedAt])->save();

        return $token->plainTextToken;
    }
}
