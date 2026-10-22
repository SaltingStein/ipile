<?php

namespace Tests\Feature\Admin;

use App\Models\CampaignPage;
use App\Models\Form;
use App\Models\User;
use App\Services\Campaigns\CampaignSettings;
use App\Services\Campaigns\CampaignStatus;
use App\Services\Campaigns\CampaignTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers Admin\CampaignPageController: creating pages from templates, the builder, pictures and the home page.
 */
class CampaignPageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_only_campaign_managers_can_open_or_change_campaigns(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->givePermissionTo('manage_settings');
        $page = CampaignPage::factory()->create();

        $this->get(route('admin.campaigns.index'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('admin.campaigns.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.campaigns.store'), ['title' => 'Sale', 'template' => 'split'])->assertForbidden();
        $this->actingAs($user)->put(route('admin.campaigns.update', $page), $this->payload($page))->assertForbidden();
        $this->actingAs($user)->delete(route('admin.campaigns.destroy', $page))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.campaigns.images.store'), ['image' => UploadedFile::fake()->image('a.png')])->assertForbidden();

        $this->assertDatabaseCount('campaign_pages', 1);
    }

    public function test_marketing_role_can_manage_campaigns(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $marketer = User::factory()->create()->assignRole('marketing');

        $this->actingAs($marketer)->get(route('admin.campaigns.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Campaigns/Index')->has('templates', 3));
    }

    public function test_new_page_starts_as_a_draft_with_the_templates_starter_content(): void
    {
        $manager = $this->manager();
        CampaignPage::factory()->create(['slug' => 'summer-sale']);

        $response = $this->actingAs($manager)->post(route('admin.campaigns.store'), ['title' => 'Summer Sale', 'template' => 'split']);

        $page = CampaignPage::query()->latest('id')->first();
        $response->assertRedirect(route('admin.campaigns.edit', $page));
        $this->assertSame('summer-sale-2', $page->slug);
        $this->assertSame(CampaignTemplate::Split, $page->template);
        $this->assertSame(CampaignStatus::Draft, $page->status);
        $this->assertSame('Summer Sale', $page->hero['headline']);
        $this->assertSame(['countdown', 'features', 'faq'], array_column($page->sections, 'type'));
        $this->assertSame($manager->id, $page->created_by);

        $this->actingAs($manager)->get(route('admin.campaigns.edit', $page))->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->component('Admin/Campaigns/Builder')->where('campaign.slug', 'summer-sale-2'));
    }

    public function test_builder_saves_and_publishes_the_page_keeping_only_known_fields(): void
    {
        $manager = $this->manager();
        $page = CampaignPage::factory()->draft()->create();
        $form = Form::factory()->site()->create();

        $this->actingAs($manager)->put(route('admin.campaigns.update', $page), $this->payload($page, [
            'status' => 'published',
            'template' => 'story',
            'accent' => 'violet',
            'hero' => [...$this->hero(), 'media' => 'video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'script' => '<b>x</b>'],
            'sections' => [
                ['id' => 'intro', 'type' => 'text', 'heading' => 'Hello', 'body' => 'Welcome.', 'onclick' => 'alert(1)'],
                ['id' => 'join', 'type' => 'form', 'form_id' => $form->id, 'heading' => 'Join'],
                ['id' => 'film', 'type' => 'video', 'video_url' => 'https://vimeo.com/76979871'],
            ],
        ]))->assertSessionHasNoErrors()->assertSessionHas('status');

        $page->refresh();
        $this->assertTrue($page->isPublished());
        $this->assertSame(CampaignTemplate::Story, $page->template);
        $this->assertSame('violet', $page->accent);
        $this->assertArrayNotHasKey('script', $page->hero);
        $this->assertSame(['id' => 'intro', 'type' => 'text', 'heading' => 'Hello', 'body' => 'Welcome.'], $page->sections[0]);
        $this->assertSame($form->id, $page->sections[1]['form_id']);
    }

    public function test_builder_rejects_unsafe_links_other_videos_and_unusable_forms(): void
    {
        $manager = $this->manager();
        $page = CampaignPage::factory()->create();
        $teamForm = Form::factory()->create();
        $draftForm = Form::factory()->site()->draft()->create();

        $response = $this->actingAs($manager)->put(route('admin.campaigns.update', $page), $this->payload($page, [
            'hero' => [...$this->hero(), 'buttons' => [['label' => 'Go', 'url' => 'javascript:alert(1)', 'style' => 'primary']], 'media' => 'video', 'video_url' => 'https://example.com/clip.mp4'],
            'sections' => [
                ['id' => 'a', 'type' => 'form', 'form_id' => $teamForm->id],
                ['id' => 'b', 'type' => 'form', 'form_id' => $draftForm->id],
                ['id' => 'c', 'type' => 'image', 'image' => '../../.env'],
                ['id' => 'd', 'type' => 'unknown'],
            ],
        ]));

        $response->assertSessionHasErrors(['hero.buttons.0.url', 'hero.video_url', 'sections.0.form_id', 'sections.1.form_id', 'sections.2.image', 'sections.3.type']);
        $this->assertSame($page->hero, $page->fresh()->hero);
    }

    public function test_pictures_upload_and_are_deleted_when_no_longer_used(): void
    {
        $manager = $this->manager();
        $page = CampaignPage::factory()->create();
        $banner = $this->actingAs($manager)->postJson(route('admin.campaigns.images.store'), ['image' => UploadedFile::fake()->image('banner.jpg', 1600, 900)])
            ->assertCreated()->json('reference');
        $section = $this->actingAs($manager)->postJson(route('admin.campaigns.images.store'), ['image' => UploadedFile::fake()->image('hall.jpg')])
            ->json('reference');
        Storage::disk('public')->assertExists([$banner, $section]);

        $this->actingAs($manager)->put(route('admin.campaigns.update', $page), $this->payload($page, [
            'hero' => [...$this->hero(), 'media' => 'image', 'image' => $banner],
            'sections' => [['id' => 'pic', 'type' => 'image', 'image' => $section]],
        ]))->assertSessionHasNoErrors();

        // Taking the picture section out deletes its file; the banner stays.
        $this->actingAs($manager)->put(route('admin.campaigns.update', $page), $this->payload($page->fresh(), ['sections' => []]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($section);
        Storage::disk('public')->assertExists($banner);

        // A copy shares the banner, so deleting the original keeps it until the copy is deleted too.
        $this->actingAs($manager)->post(route('admin.campaigns.duplicate', $page));
        $copy = CampaignPage::query()->latest('id')->first();
        $this->actingAs($manager)->delete(route('admin.campaigns.destroy', $page))->assertRedirect(route('admin.campaigns.index'));
        Storage::disk('public')->assertExists($banner);
        $this->actingAs($manager)->delete(route('admin.campaigns.destroy', $copy));
        Storage::disk('public')->assertMissing($banner);
    }

    public function test_daily_clean_up_deletes_old_pictures_no_page_uses(): void
    {
        $manager = $this->manager();
        $abandoned = $this->actingAs($manager)->postJson(route('admin.campaigns.images.store'), ['image' => UploadedFile::fake()->image('a.jpg')])->json('reference');

        Artisan::call('campaigns:prune-images');
        Storage::disk('public')->assertExists($abandoned);

        $this->travel(25)->hours();
        Artisan::call('campaigns:prune-images');
        Storage::disk('public')->assertMissing($abandoned);
    }

    public function test_a_live_page_can_replace_the_landing_page_and_drafts_cannot(): void
    {
        $manager = $this->manager();
        $draft = CampaignPage::factory()->draft()->create();
        $live = CampaignPage::factory()->create();

        $this->actingAs($manager)->put(route('admin.campaigns.home-page', $draft), ['is_home_page' => true])->assertSessionHasErrors('is_home_page');
        $this->actingAs($manager)->put(route('admin.campaigns.home-page', $live), ['is_home_page' => true])->assertSessionHasNoErrors();
        $this->assertSame($live->id, app(CampaignSettings::class)->homePageId());

        $this->post(route('logout'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Campaigns/Show')->where('page.slug', $live->slug));

        // Deleting the page brings the landing page back.
        $this->actingAs($manager)->delete(route('admin.campaigns.destroy', $live));
        $this->assertNull(app(CampaignSettings::class)->homePageId());
    }

    /**
     * @return array<string, mixed>
     */
    private function hero(): array
    {
        return ['eyebrow' => null, 'headline' => 'Big news', 'text' => null, 'buttons' => [], 'media' => 'none', 'image' => null, 'image_alt' => null, 'video_url' => null];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(CampaignPage $page, array $overrides = []): array
    {
        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'template' => $page->template->value,
            'status' => $page->status->value,
            'accent' => $page->accent,
            'meta_description' => null,
            'ends_at' => null,
            'ended_message' => null,
            'hero' => $page->hero,
            'sections' => $page->sections,
            ...$overrides,
        ];
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_campaigns');
    }
}
