<?php

namespace Tests\Feature\Translation;

use App\Ai\Agents\SiteTranslationAgent;
use App\Models\Translation;
use App\Models\TranslationString;
use App\Models\User;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use App\Services\Translation\MachineTranslator;
use App\Services\Translation\StringCollector;
use App\Services\Translation\TranslationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers site translation: the public dictionary, reporting page text, confirmation by a second visitor,
 * and machine translation within the daily limit.
 */
class SiteTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_is_offered_while_translation_is_off(): void
    {
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('siteLanguages', null));
        $this->getJson(route('translations.dictionary', 'fr'))->assertNotFound();
        $this->postJson(route('translations.missing'), ['locale' => 'fr', 'strings' => ['Sign in']])->assertUnprocessable();

        $this->assertDatabaseCount('translation_strings', 0);
    }

    public function test_public_pages_get_the_offered_languages_and_their_translations(): void
    {
        $this->switchOn(['fr', 'ar']);
        $confirmed = TranslationString::factory()->text('Sign in')->create();
        $confirmed->translations()->create(['locale' => 'fr', 'text' => 'Se connecter']);
        $unconfirmed = TranslationString::factory()->unconfirmed()->text('Made up')->create();
        $unconfirmed->translations()->create(['locale' => 'fr', 'text' => 'Inventé']);
        $vouched = TranslationString::factory()->unconfirmed()->text('Welcome')->create();
        $vouched->translations()->create(['locale' => 'fr', 'text' => 'Bienvenue', 'is_manual' => true]);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('siteLanguages.languages.0.code', 'fr')
            ->where('siteLanguages.languages.1.dir', 'rtl'));
        $this->getJson(route('translations.dictionary', 'fr'))
            ->assertOk()
            ->assertExactJson(['locale' => 'fr', 'version' => app(TranslationSettings::class)->version(), 'strings' => ['Sign in' => 'Se connecter', 'Welcome' => 'Bienvenue']]);
        $this->getJson(route('translations.dictionary', 'de'))->assertNotFound();
    }

    public function test_text_is_only_confirmed_when_a_second_visitor_reports_it(): void
    {
        $this->switchOn(['fr']);
        $report = fn (string $ip, array $strings) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('translations.missing'), ['locale' => 'fr', 'strings' => $strings])->assertNoContent();

        $report('203.0.113.1', ['  Join   the waitlist ', '12,000', str_repeat('a', StringCollector::MAX_LENGTH + 1)]);
        $string = TranslationString::query()->sole();
        $this->assertSame('Join the waitlist', $string->text);
        $this->assertNull($string->confirmed_at);
        $this->assertNotSame('203.0.113.1', $string->reporter_hash);

        $report('203.0.113.1', ['Join the waitlist']);
        $this->assertNull($string->fresh()->confirmed_at);

        $report('198.51.100.7', ['Join the waitlist']);
        $this->assertNotNull($string->fresh()->confirmed_at);
    }

    public function test_reports_from_translation_managers_are_trusted_at_once(): void
    {
        $this->switchOn(['fr']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_translations');

        $this->actingAs($manager)->postJson(route('translations.missing'), ['locale' => 'fr', 'strings' => ['Our story']])->assertNoContent();

        $this->assertNotNull(TranslationString::query()->sole()->confirmed_at);
    }

    public function test_unconfirmed_text_is_pruned_after_a_week(): void
    {
        $old = TranslationString::factory()->unconfirmed()->create(['created_at' => now()->subDays(8)]);
        $recent = TranslationString::factory()->unconfirmed()->create();
        $confirmed = TranslationString::factory()->create(['created_at' => now()->subDays(30)]);

        $this->artisan('translations:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($confirmed);
    }

    public function test_ai_model_translates_confirmed_text_without_replacing_hand_written_translations(): void
    {
        $this->switchOn(['fr'], provider: 'openai');
        $hello = TranslationString::factory()->text('Hello')->create();
        $thanks = TranslationString::factory()->text('Thank you')->create();
        $handWritten = TranslationString::factory()->text('Sign in')->create();
        $handWritten->translations()->create(['locale' => 'fr', 'text' => 'Connexion', 'is_manual' => true]);
        TranslationString::factory()->unconfirmed()->text('Spam text')->create();

        SiteTranslationAgent::fake([[
            'translations' => [
                ['id' => $hello->id, 'text' => 'Bonjour'],
                ['id' => $thanks->id, 'text' => 'Merci'],
                // Answers for texts that were not sent are ignored.
                ['id' => $handWritten->id, 'text' => 'Piraté'],
                ['id' => 999999, 'text' => 'Nope'],
            ],
        ]]);
        $versionBefore = app(TranslationSettings::class)->version();

        $saved = app(MachineTranslator::class)->translatePending();

        $this->assertSame(2, $saved);
        $this->assertSame('Bonjour', Translation::query()->where('translation_string_id', $hello->id)->value('text'));
        $this->assertSame('Connexion', $handWritten->translations()->value('text'));
        $this->assertSame(3, Translation::query()->count());
        $this->assertNotSame($versionBefore, app(TranslationSettings::class)->version());
        SiteTranslationAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Hello') && ! str_contains($prompt->prompt, 'Spam text') && ! str_contains($prompt->prompt, 'Sign in'));
    }

    public function test_daily_limit_caps_machine_translation(): void
    {
        $this->switchOn(['fr'], provider: 'openai', dailyLimit: 1);
        $first = TranslationString::factory()->text('First')->create();
        TranslationString::factory()->text('Second')->create();
        SiteTranslationAgent::fake([['translations' => [['id' => $first->id, 'text' => 'Premier']]]]);

        $this->assertSame(1, app(MachineTranslator::class)->translatePending());
        $this->assertSame(0, app(MachineTranslator::class)->remainingToday());
        $this->assertSame(0, app(MachineTranslator::class)->translatePending());
        SiteTranslationAgent::assertPromptedTimes(1);
    }

    public function test_nothing_is_sent_without_a_switched_on_ai_model(): void
    {
        $this->switchOn(['fr']);
        TranslationString::factory()->create();
        SiteTranslationAgent::fake();

        $this->assertSame(0, app(MachineTranslator::class)->translatePending());
        SiteTranslationAgent::assertPromptedTimes(0);
    }

    /**
     * @param  list<string>  $languages
     */
    private function switchOn(array $languages, ?string $provider = null, int $dailyLimit = 500): void
    {
        if ($provider !== null) {
            app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find($provider), ['is_enabled' => true, 'config' => ['api_key' => 'sk-test']]);
        }

        app(TranslationSettings::class)->update(true, $languages, $provider, $dailyLimit);
    }
}
