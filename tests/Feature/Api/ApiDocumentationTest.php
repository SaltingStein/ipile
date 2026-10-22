<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\ApiDocs\ApiDocsSettings;
use App\Services\ApiDocs\ApiDocsVisibility;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * CI guard: an /api/v1 route that the generator cannot document fails the build.
     * A PUT|PATCH route (e.g. from apiResource) is documented once, as PUT.
     */
    public function test_every_api_v1_route_is_in_the_generated_specification(): void
    {
        $this->setVisibility(ApiDocsVisibility::Public);

        $paths = $this->getJson(route('scramble.docs.document'))->assertOk()->json('paths');

        $missing = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => Str::startsWith($route->uri(), 'api/v1/'))
            ->flatMap(fn (Route $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD' || ($method === 'PATCH' && in_array('PUT', $route->methods(), true)))
                ->map(fn (string $method) => [Str::after($route->uri(), 'api/v1'), strtolower($method)]))
            ->reject(fn (array $operation) => isset($paths[$operation[0]][$operation[1]]))
            ->map(fn (array $operation) => strtoupper($operation[1]).' '.$operation[0])
            ->values()
            ->all();

        $this->assertSame([], $missing, 'These /api/v1 routes are missing from the API docs.');
    }

    public function test_sanctum_routes_are_documented_as_needing_a_bearer_token(): void
    {
        $this->setVisibility(ApiDocsVisibility::Public);

        $spec = $this->getJson(route('scramble.docs.document'))->assertOk();

        $spec->assertJsonPath('components.securitySchemes.http.scheme', 'bearer');
        $spec->assertJsonPath('paths./auth/login.post.security', []);
        $this->assertArrayNotHasKey('security', $spec->json('paths./auth/user.get'));
    }

    public function test_default_visibility_allows_only_the_api_docs_permission(): void
    {
        $this->get(route('scramble.docs.ui'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('scramble.docs.ui'))->assertForbidden();

        $docsReader = User::factory()->create()->givePermissionTo('manage_api_docs');
        $this->actingAs($docsReader)->get(route('scramble.docs.ui'))->assertOk();
        $this->actingAs($docsReader)->get(route('scramble.docs.document'))->assertOk();
    }

    public function test_signed_in_visibility_admits_registered_users_but_not_guest_accounts(): void
    {
        $this->setVisibility(ApiDocsVisibility::Authenticated);

        $this->actingAs(User::factory()->guest()->create())->get(route('scramble.docs.ui'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('scramble.docs.ui'))->assertOk();
    }

    public function test_public_visibility_admits_signed_out_visitors(): void
    {
        $this->setVisibility(ApiDocsVisibility::Public);

        $this->get(route('scramble.docs.ui'))->assertOk();
    }

    public function test_disabled_docs_are_not_found_even_for_super_admins(): void
    {
        $this->setVisibility(ApiDocsVisibility::Disabled);
        $superAdmin = User::factory()->create()->assignRole('super_admin');

        $this->actingAs($superAdmin)->get(route('scramble.docs.ui'))->assertNotFound();
        $this->actingAs($superAdmin)->get(route('scramble.docs.document'))->assertNotFound();
    }

    public function test_developer_portal_follows_the_docs_visibility(): void
    {
        $this->get(route('developers'))->assertRedirect(route('login'));

        $this->setVisibility(ApiDocsVisibility::Disabled);
        $this->get(route('developers'))->assertNotFound();

        $this->setVisibility(ApiDocsVisibility::Public);
        $this->get(route('developers'))->assertOk();
    }

    public function test_the_sidebar_offers_the_developer_portal_only_to_people_who_may_open_it(): void
    {
        $member = User::factory()->create();
        $docsReader = User::factory()->create()->givePermissionTo('manage_api_docs');

        $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('developerPortalVisible', false));
        $this->actingAs($docsReader)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('developerPortalVisible', true));

        $this->setVisibility(ApiDocsVisibility::Authenticated);
        $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('developerPortalVisible', true));

        $this->setVisibility(ApiDocsVisibility::Disabled);
        $this->actingAs($docsReader)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('developerPortalVisible', false));
    }

    public function test_developer_portal_lists_rate_limits_from_the_routes(): void
    {
        $this->setVisibility(ApiDocsVisibility::Public);

        $this->get(route('developers'))->assertInertia(fn (Assert $page) => $page
            ->component('DeveloperPortal')
            ->where('baseUrl', url('/api/v1'))
            ->where('loginMaxAttempts', 5)
            ->where('rateLimits', fn ($limits) => collect($limits)->contains(
                fn (array $limit) => $limit === ['method' => 'POST', 'path' => '/auth/register', 'per_minute' => 6, 'by' => 'ip']
            ))
            ->where('rateLimits', fn ($limits) => collect($limits)->every(fn (array $limit) => ! str_starts_with($limit['path'], '/webhooks'))));
    }

    public function test_settings_managers_can_change_visibility_from_the_dashboard(): void
    {
        $settingsManager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($settingsManager)->get(route('admin.api-docs.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ApiDocs')
                ->where('visibility', 'permission')
                ->has('options', 4));

        $this->actingAs($settingsManager)->put(route('admin.api-docs.update'), ['visibility' => 'public'])->assertSessionHasNoErrors();
        $this->assertSame(ApiDocsVisibility::Public, app(ApiDocsSettings::class)->visibility());

        $this->actingAs($settingsManager)->put(route('admin.api-docs.update'), ['visibility' => 'everyone'])->assertSessionHasErrors('visibility');
    }

    public function test_users_without_manage_settings_cannot_change_visibility(): void
    {
        $docsReader = User::factory()->create()->givePermissionTo('manage_api_docs');

        $this->actingAs($docsReader)->get(route('admin.api-docs.edit'))->assertForbidden();
        $this->actingAs($docsReader)->put(route('admin.api-docs.update'), ['visibility' => 'public'])->assertForbidden();
        $this->assertSame(ApiDocsVisibility::Permission, app(ApiDocsSettings::class)->visibility());
    }

    private function setVisibility(ApiDocsVisibility $visibility): void
    {
        app(ApiDocsSettings::class)->update($visibility);
    }
}
