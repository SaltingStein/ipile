<?php

namespace Tests\Feature\Admin;

use App\Models\LegalDocument;
use App\Models\Setting;
use App\Models\User;
use App\Services\BrandingSettings;
use App\Services\ConsentSettings;
use Database\Seeders\LegalContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalContentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions): User
    {
        $this->seed([RolesAndPermissionsSeeder::class, LegalContentSeeder::class]);

        return User::factory()->create()->givePermissionTo($permissions);
    }

    public function test_legal_editors_cannot_open_general_settings_pages(): void
    {
        $this->actingAs($this->actor(['edit_legal_content']))->get(route('admin.legal.index'))->assertOk();
        $this->get(route('admin.branding.edit'))->assertForbidden();
    }

    public function test_seeding_preserves_existing_drafts_and_public_pages_hide_unpublished_content(): void
    {
        $this->actor([]);
        $document = LegalDocument::query()->where('slug', 'privacy')->firstOrFail();
        $document->update(['body' => 'Custom draft']);
        $this->seed(LegalContentSeeder::class);

        $this->assertSame('Custom draft', $document->fresh()->body);
        $this->get(route('legal.show', 'privacy'))->assertNotFound();
        $this->getJson('/api/v1/legal/privacy')->assertNotFound();
        $this->getJson('/api/v1/legal')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_editor_can_save_a_draft_but_cannot_publish(): void
    {
        $editor = $this->actor(['edit_legal_content']);
        $this->actingAs($editor)->put(route('admin.legal.update', 'privacy'), [
            'title' => 'Updated Privacy', 'body' => 'Private draft', 'revision' => 0,
        ])->assertSessionHasNoErrors();
        $document = LegalDocument::query()->where('slug', 'privacy')->firstOrFail();
        $this->assertSame('Private draft', $document->body);
        $this->assertSame(0, $document->version);
        $this->assertDatabaseHas('legal_document_revisions', ['user_id' => $editor->id, 'action' => 'draft_saved', 'body' => 'Private draft']);
        $this->post(route('admin.legal.publish', 'privacy'), ['revision' => 1])->assertForbidden();
    }

    public function test_publishing_creates_public_snapshots_and_later_drafts_do_not_leak(): void
    {
        $this->actingAs($this->actor(['edit_legal_content', 'publish_legal_content']));
        $this->put(route('admin.legal.update', 'privacy'), ['title' => 'Privacy', 'body' => 'Public body', 'revision' => 0])->assertSessionHasNoErrors();
        $this->post(route('admin.legal.publish', 'privacy'), ['revision' => 1])->assertSessionHasNoErrors();
        $this->get(route('legal.show', 'privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('LegalPage')->where('document.body', 'Public body')->where('document.version', 1));
        $this->put(route('admin.legal.update', 'privacy'), ['title' => 'New title', 'body' => 'Unpublished body', 'revision' => 2])->assertSessionHasNoErrors();
        $this->getJson('/api/v1/legal/privacy')->assertOk()->assertJsonPath('data.body', 'Public body')->assertJsonPath('data.title', 'Privacy');
        $this->post(route('admin.legal.publish', 'privacy'), ['revision' => 3])->assertSessionHasNoErrors();
        $this->getJson('/api/v1/legal/privacy')->assertJsonPath('data.body', 'Unpublished body')->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('legal_document_revisions', 4);
    }

    public function test_published_pages_fill_placeholders_from_branding_and_keep_unset_ones(): void
    {
        $this->actingAs($this->actor(['edit_legal_content', 'publish_legal_content']));
        Setting::store(BrandingSettings::NAME, 'Ruby Workspace');
        Setting::store(BrandingSettings::ORGANISATION_NAME, 'Ruby Labs Ltd');
        $body = '[Organisation name] operates [service name]. Contact [contact email].';
        $this->put(route('admin.legal.update', 'privacy'), ['title' => '[service name] Privacy', 'body' => $body, 'revision' => 0])->assertSessionHasNoErrors();
        $this->post(route('admin.legal.publish', 'privacy'), ['revision' => 1])->assertSessionHasNoErrors();

        $this->get(route('legal.show', 'privacy'))->assertInertia(fn (Assert $page) => $page
            ->where('document.title', 'Ruby Workspace Privacy')
            ->where('document.body', 'Ruby Labs Ltd operates Ruby Workspace. Contact [contact email].')
            ->where('legalLinks.0.title', 'Ruby Workspace Privacy'));

        Setting::store(BrandingSettings::SERVICE_NAME, 'Ruby Payments');
        Setting::store(BrandingSettings::CONTACT_EMAIL, 'privacy@ruby.test');
        $this->getJson('/api/v1/legal/privacy')->assertJsonPath('data.body', 'Ruby Labs Ltd operates Ruby Payments. Contact privacy@ruby.test.');
        $this->assertSame($body, LegalDocument::query()->where('slug', 'privacy')->value('published_body'));
        $this->get(route('admin.legal.edit', 'privacy'))->assertInertia(fn (Assert $page) => $page
            ->where('placeholders.[contact email]', 'privacy@ruby.test'));
    }

    public function test_publish_only_permission_does_not_allow_editing_and_stale_revisions_are_rejected(): void
    {
        $this->actingAs($this->actor(['publish_legal_content']));
        $this->put(route('admin.legal.update', 'terms'), ['title' => 'Bad', 'body' => 'Bad', 'revision' => 0])->assertForbidden();
        $this->post(route('admin.legal.publish', 'terms'), ['revision' => 0])->assertSessionHasNoErrors();
        $this->post(route('admin.legal.publish', 'terms'), ['revision' => 0])->assertSessionHasErrors('document');
        $this->assertSame(1, LegalDocument::query()->where('slug', 'terms')->value('version'));
    }

    public function test_missing_title_and_body_are_rejected_without_an_audit_entry(): void
    {
        $this->actingAs($this->actor(['edit_legal_content']))->put(route('admin.legal.update', 'terms'), [
            'title' => '', 'body' => '', 'revision' => 0,
        ])->assertSessionHasErrors(['title', 'body']);
        $this->assertDatabaseCount('legal_document_revisions', 0);
    }

    public function test_members_guests_and_anonymous_visitors_cannot_change_legal_or_consent_settings(): void
    {
        $this->actor([]);
        $this->get(route('admin.legal.index'))->assertRedirect(route('login'));
        foreach ([User::factory()->create(), User::factory()->guest()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.legal.index'))->assertForbidden();
            $this->put(route('admin.legal.update', 'privacy'), [])->assertForbidden();
            $this->post(route('admin.legal.publish', 'privacy'), [])->assertForbidden();
            $this->put(route('admin.consent.update'), [])->assertForbidden();
        }
    }

    public function test_notice_content_is_editable_but_only_site_managers_change_display_rules(): void
    {
        $editor = $this->actor(['edit_legal_content']);
        $config = app(ConsentSettings::class)->config();
        $config['enabled'] = false;
        $config['analytics_enabled'] = false;
        $config['notices'][0]['body'] = 'Our edited cookie notice';
        $config['notices'][0]['enabled'] = false;
        $this->actingAs($editor)->put(route('admin.consent.update'), $config)->assertSessionHasNoErrors();
        $saved = app(ConsentSettings::class)->config();
        $this->assertTrue($saved['enabled']);
        $this->assertTrue($saved['analytics_enabled']);
        $this->assertTrue($saved['notices'][0]['enabled']);
        $this->assertSame('Our edited cookie notice', $saved['notices'][0]['body']);
        $this->assertSame(2, $saved['version']);

        $this->actingAs($this->actor(['manage_settings']))->put(route('admin.consent.update'), $config)->assertSessionHasNoErrors();
        $this->assertFalse(app(ConsentSettings::class)->config()['enabled']);
    }

    public function test_visitors_can_accept_reject_and_change_choices_with_a_versioned_record(): void
    {
        $this->actor([]);
        $response = $this->postJson('/api/v1/consent', ['version' => 1, 'analytics' => true, 'marketing' => false]);
        $response->assertCreated()->assertJsonPath('data.necessary', true)->assertJsonPath('data.analytics', true);
        $choice = $response->json('data');
        $this->assertDatabaseHas('consent_records', ['id' => $choice['id'], 'version' => 1, 'user_id' => null]);

        $this->withCookie(ConsentSettings::COOKIE, json_encode($choice))->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('consent.choice.analytics', true));
        $this->from(route('home'))->post(route('consent.store'), ['version' => 1, 'analytics' => false, 'marketing' => false])
            ->assertRedirect(route('home'))->assertCookie(ConsentSettings::COOKIE);
        $this->assertDatabaseCount('consent_records', 2);
    }

    public function test_disabled_categories_and_outdated_notice_versions_cannot_be_accepted(): void
    {
        $this->actor([]);
        $config = app(ConsentSettings::class)->config();
        $config['analytics_enabled'] = false;
        app(ConsentSettings::class)->update($config);
        $this->postJson('/api/v1/consent', ['version' => 1, 'analytics' => false, 'marketing' => false])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson('/api/v1/consent', ['version' => 2, 'analytics' => true, 'marketing' => false])->assertUnprocessable()->assertJsonValidationErrors('analytics');
        $this->assertDatabaseCount('consent_records', 0);
    }

    public function test_expired_or_old_choices_do_not_enable_optional_features(): void
    {
        $this->actor([]);
        foreach ([
            ['version' => 0, 'expires_at' => now()->addDay()->timestamp, 'analytics' => true],
            ['version' => 1, 'expires_at' => now()->subDay()->timestamp, 'analytics' => true],
        ] as $choice) {
            $this->withCookie(ConsentSettings::COOKIE, json_encode($choice))->get(route('home'))
                ->assertInertia(fn (Assert $page) => $page->where('consent.choice', null));
        }
    }
}
