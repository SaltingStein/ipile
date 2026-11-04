<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Auth\PermissionRegistry;
use App\Services\Auth\Permissions;
use App\Services\Integrations\IntegrationDefinition;
use App\Services\Integrations\IntegrationField;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\IntegrationType;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\Notify;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What a product registers from its own service provider (notification events, integrations, permissions)
 * works like Ìpìlẹ̀'s own entries, without editing Ìpìlẹ̀ files.
 */
class ExtensionPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_notification_event_is_listed_for_admins_and_can_be_sent(): void
    {
        Notify::define($this->orderShippedEvent());
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($this->userWith('manage_settings'))->get(route('admin.notifications.index'))
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->contains('key', 'orders.shipped')));

        Notify::event('orders.shipped')->to($user)->with(['reference' => 'ORD-7'])->send();

        Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification) => $notification->event === 'orders.shipped');
    }

    public function test_defining_a_notification_event_that_exists_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification event [auth.welcome] already exists. Use Notify::override() to replace it.');

        Notify::define($this->orderShippedEvent('auth.welcome'));
    }

    public function test_overriding_an_ipile_notification_event_replaces_it_in_place(): void
    {
        Notify::override($this->orderShippedEvent('auth.welcome'));

        $this->actingAs($this->userWith('manage_settings'))->get(route('admin.notifications.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.key', 'auth.welcome')
                ->where('events.0.label', 'Order shipped'));
    }

    public function test_a_product_integration_gets_an_admin_settings_page_with_its_secret_hidden(): void
    {
        Integrations::define(new IntegrationDefinition('acme_mail', IntegrationType::Mail, 'Acme Mail', 'Test provider.', [
            IntegrationField::secret('api_key', 'API key'),
        ]));
        $manager = $this->userWith('manage_integrations');

        $this->actingAs($manager)->put(route('admin.integrations.update', 'acme_mail'), ['is_enabled' => true, 'config' => ['api_key' => 'acme-secret']])
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)->get(route('admin.integrations.show', 'acme_mail'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('provider.label', 'Acme Mail')
                ->where('provider.is_enabled', true)
                ->where('provider.fields.0.value', null)
                ->where('provider.fields.0.has_value', true));
    }

    public function test_defining_an_integration_that_exists_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Integration [zeptomail] already exists. Use Integrations::override() to replace it.');

        Integrations::define(new IntegrationDefinition('zeptomail', IntegrationType::Mail, 'Another', 'Test provider.', []));
    }

    public function test_a_product_admin_permission_opens_the_admin_overview(): void
    {
        Permissions::define('manage_invoices', 'Manage invoices', admin: true);
        Permissions::define('view_reports', 'See reports');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(User::factory()->create()->givePermissionTo('manage_invoices'))->get(route('admin.index'))->assertOk();
        $this->actingAs(User::factory()->create()->givePermissionTo('view_reports'))->get(route('admin.index'))->assertForbidden();
    }

    public function test_defining_a_permission_that_exists_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Permissions::define('manage_settings');
    }

    public function test_every_permission_a_route_checks_is_registered(): void
    {
        $registry = app(PermissionRegistry::class);

        $unregistered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RouteDefinition $route) => collect($route->gatherMiddleware())
                ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'permission:'))
                ->flatMap(fn (string $middleware) => explode('|', Str::after($middleware, 'permission:')))
                ->map(fn (string $permission) => [$permission, $route->uri()]))
            ->reject(fn (array $use) => $registry->has($use[0]))
            ->map(fn (array $use) => "{$use[0]} (on /{$use[1]})")
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], $unregistered, 'Register these permissions with Permissions::define() in a service provider.');
    }

    private function orderShippedEvent(string $key = 'orders.shipped'): NotificationEvent
    {
        return new NotificationEvent($key, 'Orders', 'Order shipped', 'Sent when an order leaves the warehouse.',
            [NotificationEvent::MAIL], [NotificationEvent::MAIL], ['reference' => 'ORD-1042'],
            'Your order {reference} has shipped', 'Your order {reference} is on its way.');
    }

    private function userWith(string $permission): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo($permission);
    }
}
