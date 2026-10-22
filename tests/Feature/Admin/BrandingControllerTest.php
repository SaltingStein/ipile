<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\BrandingSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrandingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_defaults_and_can_save_name_and_version(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->get(route('admin.branding.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Branding')
                ->where('settings', ['name' => 'Ìpìlẹ̀', 'organisation_name' => null, 'service_name' => null, 'contact_email' => null, 'version' => '0.1.0', 'logo_url' => null, 'launched_on' => null]));

        $this->actingAs($manager)->from(route('admin.branding.edit'))->post(route('admin.branding.update'), [
            'name' => 'Ruby Workspace', 'version' => '1.2.3-beta.1', 'remove_logo' => false,
        ])->assertRedirect(route('admin.branding.edit'))->assertSessionHas('status', 'Branding updated.');

        $this->assertDatabaseHas('settings', ['key' => BrandingSettings::NAME, 'value' => 'Ruby Workspace']);
        $this->assertDatabaseHas('settings', ['key' => BrandingSettings::VERSION, 'value' => '1.2.3-beta.1']);
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('branding.name', 'Ruby Workspace')->where('branding.version', '1.2.3-beta.1'));
    }

    public function test_manager_can_set_and_clear_the_organisation_service_and_contact_details(): void
    {
        $this->actingAs($this->manager())->post(route('admin.branding.update'), [
            'name' => 'Ruby Workspace', 'organisation_name' => 'Ruby Labs Ltd', 'service_name' => 'Ruby Payments', 'contact_email' => 'privacy@ruby.test', 'version' => '1.0.0', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('branding.organisation_name', 'Ruby Labs Ltd')->where('branding.service_name', 'Ruby Payments')->where('branding.contact_email', 'privacy@ruby.test'));

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby Workspace', 'organisation_name' => '', 'service_name' => '', 'contact_email' => '', 'version' => '1.0.0', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::valueOf(BrandingSettings::ORGANISATION_NAME));
        $this->assertNull(Setting::valueOf(BrandingSettings::SERVICE_NAME));
        $this->assertSame('Ruby Workspace', app(BrandingSettings::class)->organisationName());
        $this->assertSame('Ruby Workspace', app(BrandingSettings::class)->serviceName());

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby Workspace', 'contact_email' => 'not-an-email', 'version' => '1.0.0', 'remove_logo' => false,
        ])->assertSessionHasErrors('contact_email');
    }

    public function test_ipile_credit_is_independent_of_the_brand_name_and_can_be_hidden(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->get(route('admin.branding.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('creditVisible', true));

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby Workspace', 'version' => '1.0.0', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('branding.name', 'Ruby Workspace')
            ->where('boilerplateCredit', 'Ìpìlẹ̀'));

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby Workspace', 'version' => '1.0.0', 'remove_logo' => false, 'boilerplate_credit_visible' => false,
        ])->assertSessionHasNoErrors();
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('boilerplateCredit', null));
        $this->get(route('admin.branding.edit'))->assertInertia(fn (Assert $page) => $page->where('creditVisible', false));

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby Workspace', 'version' => '1.0.0', 'remove_logo' => false, 'boilerplate_credit_visible' => true,
        ])->assertSessionHasNoErrors();
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('boilerplateCredit', 'Ìpìlẹ̀'));
    }

    public function test_about_page_can_be_hidden_and_shown_from_branding(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->get(route('admin.branding.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('aboutPageVisible', true));

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => false, 'about_page_visible' => false,
        ])->assertSessionHasNoErrors();

        $this->get(route('about.boilerplate'))->assertNotFound();
        $this->get(route('admin.branding.edit'))->assertInertia(fn (Assert $page) => $page->where('aboutPageVisible', false));

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();
        $this->get(route('about.boilerplate'))->assertNotFound();

        $this->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => false, 'about_page_visible' => true,
        ])->assertSessionHasNoErrors();
        $this->get(route('about.boilerplate'))->assertOk();
    }

    public function test_launch_date_is_saved_shared_publicly_and_can_be_cleared(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'launched_on' => '2026-10-14', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('branding.launched_on', '2026-10-14'));

        $this->actingAs($manager)->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'launched_on' => '', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::valueOf(BrandingSettings::LAUNCHED_ON));
    }

    public function test_upload_is_stored_and_shared_publicly(): void
    {
        Storage::fake('public');

        $this->actingAs($this->manager())->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => false,
            'logo' => UploadedFile::fake()->image('company.png', 300, 100),
        ])->assertSessionHasNoErrors();

        $path = Setting::valueOf(BrandingSettings::LOGO);
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('branding/', $path);
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('branding.logo_url', Storage::disk('public')->url($path)));
    }

    public function test_replacing_logo_removes_previous_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/old.png', 'old');
        Setting::store(BrandingSettings::LOGO, 'branding/old.png');

        $this->actingAs($this->manager())->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => false,
            'logo' => UploadedFile::fake()->image('replacement.png'),
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('branding/old.png');
        Storage::disk('public')->assertExists(Setting::valueOf(BrandingSettings::LOGO));
    }

    public function test_text_changes_preserve_logo_and_explicit_removal_deletes_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', 'logo');
        Setting::store(BrandingSettings::LOGO, 'branding/logo.png');
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('admin.branding.update'), [
            'name' => 'Updated', 'version' => '2.0.0', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();
        $this->assertSame('branding/logo.png', Setting::valueOf(BrandingSettings::LOGO));
        Storage::disk('public')->assertExists('branding/logo.png');

        $this->post(route('admin.branding.update'), [
            'name' => 'Updated', 'version' => '2.0.0', 'remove_logo' => true,
        ])->assertSessionHasNoErrors();
        $this->assertNull(Setting::valueOf(BrandingSettings::LOGO));
        Storage::disk('public')->assertMissing('branding/logo.png');
    }

    public function test_unauthenticated_visitors_cannot_manage_branding(): void
    {
        $this->get(route('admin.branding.edit'))->assertRedirect(route('login'));
        $this->post(route('admin.branding.update'), [])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('settings', ['key' => BrandingSettings::NAME]);
    }

    public function test_members_and_guests_cannot_upload_logos_or_change_branding(): void
    {
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach ([User::factory()->create(), User::factory()->guest()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.branding.edit'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.branding.update'), [
                'name' => 'Unauthorized', 'version' => '9.0.0', 'remove_logo' => false,
                'logo' => UploadedFile::fake()->image('bad.png'),
            ])->assertForbidden();
        }
        $this->assertDatabaseMissing('settings', ['key' => BrandingSettings::NAME]);
        Storage::disk('public')->assertDirectoryEmpty('branding');
    }

    public function test_super_admin_can_save_branding(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'))->post(route('admin.branding.update'), [
            'name' => 'Super Admin Brand', 'version' => '1.0.0', 'remove_logo' => false,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Super Admin Brand', Setting::valueOf(BrandingSettings::NAME));
    }

    #[DataProvider('invalidText')]
    public function test_invalid_brand_text_is_rejected(array $fields, string $error): void
    {
        $this->actingAs($this->manager())->post(route('admin.branding.update'), array_replace([
            'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => false,
        ], $fields))->assertSessionHasErrors($error);
        $this->assertDatabaseMissing('settings', ['key' => BrandingSettings::NAME]);
    }

    public static function invalidText(): array
    {
        return [
            'empty name' => [['name' => ''], 'name'],
            'long name' => [['name' => str_repeat('a', 81)], 'name'],
            'invalid version' => [['version' => 'latest'], 'version'],
            'long version' => [['version' => '1.0.0-'.str_repeat('a', 32)], 'version'],
            'invalid removal flag' => [['remove_logo' => 'yes'], 'remove_logo'],
            'invalid about page flag' => [['about_page_visible' => 'yes'], 'about_page_visible'],
            'invalid credit flag' => [['boilerplate_credit_visible' => 'yes'], 'boilerplate_credit_visible'],
            'invalid launch date' => [['launched_on' => '14/10/2026'], 'launched_on'],
        ];
    }

    public function test_unsafe_oversized_and_oversized_dimension_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $manager = $this->manager();
        $uploads = [
            UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->create('logo.php', 1, 'text/x-php'),
            UploadedFile::fake()->image('huge.png')->size(2049),
            UploadedFile::fake()->image('wide.png', 4097, 1),
        ];
        foreach ($uploads as $logo) {
            $this->actingAs($manager)->post(route('admin.branding.update'), [
                'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => false, 'logo' => $logo,
            ])->assertSessionHasErrors('logo');
        }
        $this->assertDatabaseMissing('settings', ['key' => BrandingSettings::LOGO]);
        Storage::disk('public')->assertDirectoryEmpty('branding');
    }

    public function test_upload_and_removal_cannot_be_requested_together(): void
    {
        Storage::fake('public');
        $this->actingAs($this->manager())->post(route('admin.branding.update'), [
            'name' => 'Ruby', 'version' => '1.0.0', 'remove_logo' => true,
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertSessionHasErrors('logo');
        Storage::disk('public')->assertDirectoryEmpty('branding');
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }
}
