<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_needs_a_recent_password_confirmation(): void
    {
        $this->actingAs(User::factory()->create())->get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
    }

    public function test_guest_accounts_cannot_manage_tokens(): void
    {
        $this->confirmedAs(User::factory()->guest()->create())->get(route('api-tokens.index'))->assertRedirect(route('register'));
    }

    public function test_a_created_token_is_shown_once_and_works_on_the_api(): void
    {
        $user = User::factory()->create();

        $this->confirmedAs($user)
            ->post(route('api-tokens.store'), ['name' => 'Reporting script', 'expires_in_days' => 30])
            ->assertRedirect(route('api-tokens.index'));

        $plainText = null;
        $this->get(route('api-tokens.index'))->assertInertia(function (Assert $page) use (&$plainText) {
            $page->component('Settings/ApiTokens')->where('tokens.0.name', 'Reporting script')->whereType('newToken', 'string');
            $plainText = $page->toArray()['props']['newToken'];
        });

        $this->get(route('api-tokens.index'))->assertInertia(fn (Assert $page) => $page->where('newToken', null));

        $token = PersonalAccessToken::query()->sole();
        $this->assertTrue($token->expires_at->lessThanOrEqualTo(now()->addDays(30)));
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'api.token_created']);

        auth()->forgetGuards();
        $this->getJson(route('api.v1.auth.user'), ['Authorization' => 'Bearer '.$plainText])->assertJsonPath('data.id', $user->id);
    }

    public function test_expiry_choices_never_exceed_the_dashboard_lifetime(): void
    {
        Setting::store(AuthenticationSettings::API_TOKEN_LIFETIME_DAYS, '60');

        $this->confirmedAs(User::factory()->create())->get(route('api-tokens.index'))->assertInertia(fn (Assert $page) => $page
            ->where('lifetimeDays', 60)
            ->where('expiryChoices', [7, 30]));
    }

    public function test_users_can_revoke_only_their_own_tokens(): void
    {
        $user = User::factory()->create();
        $mine = $user->createToken('Mine')->accessToken;
        $theirs = User::factory()->create()->createToken('Theirs')->accessToken;

        $this->confirmedAs($user)->delete(route('api-tokens.destroy', $theirs->id))->assertNotFound();
        $this->confirmedAs($user)->delete(route('api-tokens.destroy', $mine->id))->assertSessionHas('status');

        $this->assertModelMissing($mine);
        $this->assertModelExists($theirs);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'api.token_revoked']);
    }

    private function confirmedAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
