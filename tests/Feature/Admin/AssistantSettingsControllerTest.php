<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Assistant\AssistantSettings;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers Admin\AssistantSettingsController: switching the site assistant on with an AI model and allowed figures.
 */
class AssistantSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_settings_managers_can_change_the_assistant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->givePermissionTo('manage_blog');

        $this->actingAs($user)->get(route('admin.assistant.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.assistant.update'), ['enabled' => true, 'provider' => 'openai', 'metrics' => [], 'daily_messages' => 10])->assertForbidden();

        $this->assertFalse(app(AssistantSettings::class)->enabled());
    }

    public function test_manager_switches_the_assistant_on_with_figures_and_sees_the_fixed_guard_rails(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find('openai'), ['is_enabled' => true, 'config' => ['api_key' => 'sk-test']]);

        $this->actingAs($manager)->put(route('admin.assistant.update'), ['enabled' => true, 'provider' => 'openai', 'metrics' => ['blog.posts'], 'daily_messages' => 20, 'lockout' => 'sign_out'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['source' => 'built_in', 'enabled' => true, 'provider' => 'openai', 'metrics' => ['blog.posts'], 'insights' => [], 'help_enabled' => true, 'business_context' => '', 'activity_retention_days' => 90, 'daily_messages' => 20, 'lockout' => 'sign_out', 'staff_modes' => []], app(AssistantSettings::class)->all());
        $this->actingAs($manager)->get(route('admin.assistant.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Assistant')
            ->where('blockedTables', fn ($tables) => collect($tables)->contains('integrations'))
            ->where('protectedColumns.users', fn ($columns) => collect($columns)->contains('password')));
        $this->actingAs($manager)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantEnabled', true));
    }

    public function test_the_ai_model_must_be_switched_on_and_figures_must_exist(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($manager)->put(route('admin.assistant.update'), ['enabled' => true, 'provider' => null, 'metrics' => [], 'daily_messages' => 10])->assertSessionHasErrors('provider');
        $this->actingAs($manager)->put(route('admin.assistant.update'), ['enabled' => true, 'provider' => 'openai', 'metrics' => [], 'daily_messages' => 10])->assertSessionHasErrors('provider');
        $this->actingAs($manager)->put(route('admin.assistant.update'), ['enabled' => false, 'provider' => null, 'metrics' => ['users.passwords'], 'daily_messages' => 10, 'lockout' => 'suspend'])->assertSessionHasErrors('metrics.0');
        $this->actingAs($manager)->put(route('admin.assistant.update'), ['enabled' => false, 'provider' => null, 'metrics' => [], 'daily_messages' => 10, 'lockout' => 'nothing'])->assertSessionHasErrors('lockout');
        // Suspending everyone is the default.
        $this->assertSame('suspend', app(AssistantSettings::class)->lockout());
    }
}
