<?php

namespace Tests\Feature\Campaigns;

use App\Models\CampaignPage;
use App\Models\Form;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers CampaignController: the public page at /c/{slug}, its form, and the views, clicks and sign-ups figures.
 */
class CampaignPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_pages_are_public_and_count_each_visitor_once(): void
    {
        $page = CampaignPage::factory()->create();

        $this->get(route('campaigns.show', $page->slug))->assertOk()->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Campaigns/Show')
            ->where('page.title', $page->title)
            ->where('isPreview', false)
            ->where('editUrl', null));
        $this->get(route('campaigns.show', $page->slug))->assertOk();

        $this->assertSame(1, $page->fresh()->views);
    }

    public function test_drafts_are_hidden_from_visitors_but_previewed_by_managers_without_counting(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $draft = CampaignPage::factory()->draft()->create();
        $manager = User::factory()->create()->givePermissionTo('manage_campaigns');

        $this->get(route('campaigns.show', $draft->slug))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('campaigns.show', $draft->slug))->assertNotFound();
        $this->actingAs($manager)->get(route('campaigns.show', $draft->slug))->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->where('isPreview', true)->where('editUrl', route('admin.campaigns.edit', $draft)));

        $this->assertSame(0, $draft->fresh()->views);
    }

    public function test_form_answers_are_saved_with_the_form_and_count_as_a_sign_up(): void
    {
        [$page, $form] = $this->pageWithForm();

        $this->from(route('campaigns.show', $page->slug))
            ->post(route('campaigns.submit', $page->slug), ['answers' => ['full_name' => 'Ada Obi', 'email' => 'ada@example.com', 'ticket' => 'Student']])
            ->assertRedirect(route('campaigns.show', $page->slug))
            ->assertSessionHas('campaign_submitted', $page->id);

        $this->assertSame(['full_name' => 'Ada Obi', 'email' => 'ada@example.com', 'ticket' => 'Student'], $form->submissions()->sole()->data);
        $this->assertSame(1, $page->fresh()->conversions);

        $this->post(route('campaigns.submit', $page->slug), ['answers' => ['email' => 'not-an-email']])->assertSessionHasErrors(['answers.full_name', 'answers.email']);
        $this->assertSame(1, $form->submissions()->count());
    }

    public function test_bots_filling_the_spam_trap_are_thanked_and_ignored(): void
    {
        [$page, $form] = $this->pageWithForm();

        $this->post(route('campaigns.submit', $page->slug), ['answers' => ['full_name' => 'Bot'], 'hp_check' => 'spam'])->assertSessionHas('campaign_submitted', $page->id);

        $this->assertSame(0, $form->submissions()->count());
        $this->assertSame(0, $page->fresh()->conversions);
    }

    public function test_ended_pages_stop_taking_sign_ups_and_clicks(): void
    {
        [$page, $form] = $this->pageWithForm(['ends_at' => now()->subMinute()]);

        $this->get(route('campaigns.show', $page->slug))->assertInertia(fn (Assert $inertia) => $inertia->where('page.has_ended', true));
        $this->post(route('campaigns.submit', $page->slug), ['answers' => ['full_name' => 'Ada Obi']])->assertSessionHasErrors('form');
        $this->post(route('campaigns.click', $page->slug))->assertNoContent();

        $this->assertSame(0, $form->submissions()->count());
        $this->assertSame(0, $page->fresh()->cta_clicks);
    }

    public function test_button_clicks_are_counted_without_a_csrf_token_and_do_not_change_the_edit_time(): void
    {
        $page = CampaignPage::factory()->create(['updated_at' => now()->subDay()]);
        $editedAt = $page->updated_at->toIso8601String();

        $this->post(route('campaigns.click', $page->slug))->assertNoContent();
        $this->post(route('campaigns.click', CampaignPage::factory()->draft()->create()->slug))->assertNoContent();

        $this->assertSame(1, $page->fresh()->cta_clicks);
        $this->assertSame($editedAt, $page->fresh()->updated_at->toIso8601String());
    }

    public function test_drafts_and_pages_without_a_form_refuse_answers(): void
    {
        $withoutForm = CampaignPage::factory()->withSections([])->create();
        [$draft] = $this->pageWithForm(['status' => 'draft']);

        $this->post(route('campaigns.submit', $withoutForm->slug), ['answers' => []])->assertNotFound();
        $this->post(route('campaigns.submit', $draft->slug), ['answers' => []])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: CampaignPage, 1: Form}
     */
    private function pageWithForm(array $attributes = []): array
    {
        $form = Form::factory()->site()->create();
        $page = CampaignPage::factory()
            ->withSections([['id' => 'join', 'type' => 'form', 'form_id' => $form->id, 'heading' => 'Join', 'text' => null]])
            ->create($attributes);

        return [$page, $form];
    }
}
