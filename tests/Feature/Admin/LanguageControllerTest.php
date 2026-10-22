<?php

namespace Tests\Feature\Admin;

use App\Models\TranslationString;
use App\Models\User;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use App\Services\Translation\TranslationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers Admin\LanguageController: the translation switch, languages, AI model, and editing translations.
 */
class LanguageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_translation_managers_can_open_or_change_languages(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->givePermissionTo('manage_settings');
        $string = TranslationString::factory()->create();

        $this->get(route('admin.languages.edit'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('admin.languages.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.languages.update'), $this->settings())->assertForbidden();
        $this->actingAs($user)->put(route('admin.languages.strings.update', $string), ['locale' => 'fr', 'text' => 'x'])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.languages.strings.destroy', $string))->assertForbidden();

        $this->assertFalse(app(TranslationSettings::class)->enabled());
        $this->assertModelExists($string);
    }

    public function test_manager_switches_translation_on_with_languages_and_an_ai_model(): void
    {
        $manager = $this->manager();
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find('openai'), ['is_enabled' => true, 'config' => ['api_key' => 'sk-test']]);

        $this->actingAs($manager)->put(route('admin.languages.update'), $this->settings(['provider' => 'openai', 'daily_limit' => 200]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['enabled' => true, 'languages' => ['fr', 'yo'], 'provider' => 'openai', 'daily_limit' => 200], app(TranslationSettings::class)->all());
        $this->actingAs($manager)->get(route('admin.languages.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Languages')
            ->where('machine.available', true)
            ->where('filters.locale', 'fr'));
    }

    public function test_settings_need_a_language_and_a_switched_on_ai_model(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->put(route('admin.languages.update'), $this->settings(['languages' => []]))->assertSessionHasErrors('languages');
        $this->actingAs($manager)->put(route('admin.languages.update'), $this->settings(['provider' => 'openai']))->assertSessionHasErrors('provider');
        $this->actingAs($manager)->put(route('admin.languages.update'), $this->settings(['languages' => ['xx']]))->assertSessionHasErrors('languages.0');

        $this->assertFalse(app(TranslationSettings::class)->enabled());
    }

    public function test_hand_written_translations_are_saved_cleared_and_texts_removed(): void
    {
        $manager = $this->manager();
        app(TranslationSettings::class)->update(true, ['fr'], null, 100);
        $string = TranslationString::factory()->unconfirmed()->text('Get started')->create();

        $this->actingAs($manager)->put(route('admin.languages.strings.update', $string), ['locale' => 'fr', 'text' => ' Commencer '])->assertSessionHasNoErrors();
        $translation = $string->translations()->sole();
        $this->assertSame(['Commencer', true], [$translation->text, $translation->is_manual]);
        $this->assertNotNull($string->fresh()->confirmed_at);
        $this->getJson(route('translations.dictionary', 'fr'))->assertJsonPath('strings.Get started', 'Commencer');

        $this->actingAs($manager)->put(route('admin.languages.strings.update', $string), ['locale' => 'de', 'text' => 'Los'])->assertSessionHasErrors('locale');
        $this->actingAs($manager)->put(route('admin.languages.strings.update', $string), ['locale' => 'fr', 'text' => ''])->assertSessionHasNoErrors();
        $this->assertSame(0, $string->translations()->count());

        $this->actingAs($manager)->delete(route('admin.languages.strings.destroy', $string))->assertSessionHasNoErrors();
        $this->assertModelMissing($string);
    }

    public function test_translate_now_explains_when_no_ai_model_is_chosen(): void
    {
        $manager = $this->manager();
        app(TranslationSettings::class)->update(true, ['fr'], null, 100);

        $this->actingAs($manager)->post(route('admin.languages.translate'))->assertSessionHasErrors('provider');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return ['enabled' => true, 'languages' => ['fr', 'yo'], 'provider' => null, 'daily_limit' => 500, ...$overrides];
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_translations');
    }
}
