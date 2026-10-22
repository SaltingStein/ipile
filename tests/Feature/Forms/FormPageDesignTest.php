<?php

namespace Tests\Feature\Forms;

use App\Models\Form;
use App\Models\Team;
use App\Models\User;
use App\Services\Forms\FormLayout;
use App\Services\Teams\TeamManager;
use App\Services\Teams\TeamRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers FormMediaController, the layout setting and the builder's share/menu details.
 */
class FormPageDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_owner_uploads_a_banner_image_and_visitors_see_it_with_the_chosen_layout(): void
    {
        $form = Form::factory()->create();

        $this->actingAs($form->team->owner)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->image('banner.jpg', 1600, 600)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Banner uploaded.');
        $this->actingAs($form->team->owner)->put(route('forms.update', $form), $this->payload($form, ['layout' => 'designed']))
            ->assertSessionHasNoErrors();

        $form->refresh();
        $this->assertSame('image', $form->media_type);
        $this->assertSame(FormLayout::Designed, $form->layout);
        Storage::disk('public')->assertExists($form->media_path);

        auth()->logout();
        $this->get(route('forms.public.show', $form->slug))->assertInertia(fn (Assert $page) => $page
            ->where('form.layout', 'designed')
            ->where('form.media.type', 'image')
            ->where('form.media.url', Storage::disk('public')->url($form->media_path)));
    }

    public function test_video_replaces_the_image_and_the_old_file_is_deleted(): void
    {
        $form = Form::factory()->create();
        $owner = $form->team->owner;
        $this->actingAs($owner)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->image('banner.png')]);
        $imagePath = $form->fresh()->media_path;

        $this->actingAs($owner)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->create('intro.mp4', 500, 'video/mp4')])
            ->assertSessionHasNoErrors();

        $form->refresh();
        $this->assertSame('video', $form->media_type);
        Storage::disk('public')->assertMissing($imagePath);
        Storage::disk('public')->assertExists($form->media_path);
    }

    public function test_other_file_types_are_rejected(): void
    {
        $form = Form::factory()->create();

        $this->actingAs($form->team->owner)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors(['media' => 'Upload a JPG, PNG or WebP image, or an MP4 or WebM video.']);

        $this->assertNull($form->fresh()->media_path);
    }

    public function test_owner_removes_the_banner(): void
    {
        $form = Form::factory()->create();
        $owner = $form->team->owner;
        $this->actingAs($owner)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->image('banner.jpg')]);
        $path = $form->fresh()->media_path;

        $this->actingAs($owner)->delete(route('forms.media.destroy', $form))->assertSessionHas('status', 'Banner removed.');

        $this->assertNull($form->fresh()->media_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_members_and_outsiders_cannot_change_the_banner(): void
    {
        $team = Team::factory()->create();
        $form = Form::factory()->for($team)->create();
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => TeamRole::Member->value]);

        $this->actingAs($member)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->image('banner.jpg')])->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->image('banner.jpg')])->assertNotFound();
        $this->assertNull($form->fresh()->media_path);
    }

    public function test_sign_up_questions_have_no_banner(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_forms');
        $form = Form::factory()->registration()->create();

        $this->actingAs($manager)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->image('banner.jpg')])->assertNotFound();
    }

    public function test_deleting_a_form_or_its_team_removes_the_banner_file(): void
    {
        $form = Form::factory()->create();
        $teamForm = Form::factory()->create();
        $this->actingAs($form->team->owner)->post(route('forms.media.store', $form), ['media' => UploadedFile::fake()->image('a.jpg')]);
        $this->actingAs($teamForm->team->owner)->post(route('forms.media.store', $teamForm), ['media' => UploadedFile::fake()->image('b.jpg')]);
        $formPath = $form->fresh()->media_path;
        $teamFormPath = $teamForm->fresh()->media_path;

        $this->actingAs($form->team->owner)->delete(route('forms.destroy', $form));
        app(TeamManager::class)->delete($teamForm->team);

        Storage::disk('public')->assertMissing($formPath);
        Storage::disk('public')->assertMissing($teamFormPath);
    }

    public function test_builder_tells_menu_managers_whether_the_form_is_already_in_each_menu(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $form = Form::factory()->create(['slug' => 'summit']);
        $owner = $form->team->owner;

        $this->actingAs($owner)->get(route('forms.edit', $form))->assertInertia(fn (Assert $page) => $page->where('menus', null));

        $owner->givePermissionTo('manage_settings');
        $this->actingAs($owner)->post(route('admin.navigation.links.store'), ['menu' => 'site', 'label' => 'Summit', 'href' => '/f/summit']);

        $this->actingAs($owner)->get(route('forms.edit', $form))->assertInertia(fn (Assert $page) => $page
            ->where('menus', ['path' => '/f/summit', 'site' => true, 'workspace' => false])
            ->has('layouts', 2)
            ->has('mediaLimits.image_kb'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Form $form, array $overrides = []): array
    {
        return [
            'title' => $form->title,
            'description' => null,
            'slug' => $form->slug,
            'status' => 'published',
            'submit_label' => null,
            'success_message' => null,
            'max_submissions' => null,
            'closes_at' => null,
            'sends_confirmation' => false,
            'fields' => $form->fields,
            ...$overrides,
        ];
    }
}
