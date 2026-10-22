<?php

namespace Tests\Feature\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Forms\FormLayout;
use App\Services\Forms\FormStatus;
use App\Services\Teams\TeamRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FormDuplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    #[DataProvider('sourceStatuses')]
    public function test_owner_copies_saved_content_into_a_private_draft_without_responses(string $status): void
    {
        $this->freezeTime();
        $source = Form::factory()->create([
            'title' => 'Annual summit', 'status' => $status, 'description' => 'Choose your ticket.',
            'layout' => FormLayout::Designed, 'submit_label' => 'Book a place',
            'success_message' => 'Your place is booked.', 'max_submissions' => 1,
            'closes_at' => now()->subDay(), 'sends_confirmation' => true,
        ]);
        FormSubmission::factory()->for($source)->create();
        $original = $source->fresh()->getAttributes();
        $owner = $source->team->owner;

        $response = $this->actingAs($owner)->post(route('forms.duplicate', $source));

        $copy = Form::query()->whereKeyNot($source->id)->sole();
        $response->assertRedirect(route('forms.edit', $copy));
        $response->assertSessionHas('status', 'Copied as a new draft. Review the settings before publishing.');
        $this->assertSame('Copy of Annual summit', $copy->title);
        $this->assertSame($source->team_id, $copy->team_id);
        $this->assertSame($owner->id, $copy->created_by);
        $this->assertSame(FormStatus::Draft, $copy->status);
        $this->assertNotSame($source->slug, $copy->slug);
        $this->assertNull($copy->purpose);
        $this->assertNull($copy->closes_at);
        foreach (['description', 'fields', 'layout', 'submit_label', 'success_message', 'max_submissions', 'sends_confirmation'] as $attribute) {
            $this->assertSame($source->$attribute, $copy->$attribute);
        }
        $this->assertSame(0, $copy->submissions()->count());
        $this->assertDatabaseCount('form_submissions', 1);
        $this->assertSame($original, $source->fresh()->getAttributes());
        $this->get(route('forms.public.show', $copy->slug))->assertNotFound();
        $this->get(route('forms.edit', $copy))->assertInertia(fn (Assert $page) => $page
            ->where('form.id', $copy->id)->where('form.status', 'draft')->where('form.submissions_count', 0));
    }

    public static function sourceStatuses(): array
    {
        return ['draft' => ['draft'], 'published' => ['published'], 'closed' => ['closed']];
    }

    public function test_team_admin_can_copy_a_form_and_becomes_its_creator(): void
    {
        $source = Form::factory()->create();
        $admin = User::factory()->create();
        $source->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

        $this->actingAs($admin)->post(route('forms.duplicate', $source))->assertRedirect();

        $copy = Form::query()->whereKeyNot($source->id)->sole();
        $this->assertSame($source->team_id, $copy->team_id);
        $this->assertSame($admin->id, $copy->created_by);
    }

    public function test_copy_stays_in_its_source_scope_and_ignores_posted_settings(): void
    {
        $source = Form::factory()->create();
        $otherTeam = Team::factory()->for($source->team->owner, 'owner')->create();
        $owner = $source->team->owner;
        $owner->forceFill(['current_team_id' => $otherTeam->id])->save();

        $this->actingAs($owner)->post(route('forms.duplicate', $source), [
            'team_id' => $otherTeam->id, 'status' => 'published', 'purpose' => 'registration',
            'slug' => 'injected-slug', 'fields' => [], 'created_by' => $otherTeam->owner_id,
            'media_path' => 'other-file.png',
        ])->assertRedirect();

        $copy = Form::query()->whereKeyNot($source->id)->sole();
        $this->assertSame($source->team_id, $copy->team_id);
        $this->assertSame($source->fields, $copy->fields);
        $this->assertSame(FormStatus::Draft, $copy->status);
        $this->assertNull($copy->purpose);
        $this->assertNull($copy->media_path);
        $this->assertNotSame('injected-slug', $copy->slug);
    }

    #[DataProvider('refusedUsers')]
    public function test_members_and_outsiders_cannot_copy_forms(string $role, int $status): void
    {
        $source = Form::factory()->create();
        $user = User::factory()->create();
        if ($role === 'member') {
            $source->team->users()->attach($user, ['role' => TeamRole::Member->value]);
        }

        $this->actingAs($user)->post(route('forms.duplicate', $source))->assertStatus($status);
        $this->assertDatabaseCount('forms', 1);
    }

    public static function refusedUsers(): array
    {
        return ['member' => ['member', 403], 'outsider' => ['outsider', 404]];
    }

    public function test_visitors_must_sign_in_before_copying(): void
    {
        $source = Form::factory()->create();
        $this->post(route('forms.duplicate', $source))->assertRedirect(route('login'));
        $this->assertDatabaseCount('forms', 1);
    }

    public function test_guest_accounts_must_register_even_if_they_own_the_source_team(): void
    {
        $team = Team::factory()->for(User::factory()->guest(), 'owner')->create();
        $source = Form::factory()->for($team)->create();

        $this->actingAs($team->owner)->post(route('forms.duplicate', $source))->assertRedirect(route('register'));
        $this->assertDatabaseCount('forms', 1);
    }

    public function test_site_forms_require_manage_forms_even_for_a_team_owner(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $source = Form::factory()->site()->create();
        $owner = Team::factory()->create()->owner->givePermissionTo('manage_settings');

        $this->actingAs($owner)->post(route('forms.duplicate', $source))->assertForbidden();
        $this->assertDatabaseCount('forms', 1);
    }

    public function test_a_site_manager_can_copy_a_site_form_without_assigning_it_to_their_current_team(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $source = Form::factory()->site()->create();
        $team = Team::factory()->create();
        $manager = $team->owner->givePermissionTo('manage_forms');
        $manager->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($manager)->post(route('forms.duplicate', $source))->assertRedirect();

        $copy = Form::query()->whereKeyNot($source->id)->sole();
        $this->assertNull($copy->team_id);
        $this->get(route('forms.edit', $copy))->assertInertia(fn (Assert $page) => $page->where('backUrl', route('admin.forms.index')));
    }

    public function test_sign_up_questions_copy_to_an_ordinary_site_form(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_forms');
        $source = Form::factory()->registration()->create();

        $this->actingAs($manager)->post(route('forms.duplicate', $source))->assertRedirect();

        $copy = Form::query()->whereKeyNot($source->id)->sole();
        $this->assertFalse($copy->isRegistrationForm());
        $this->assertNull($copy->purpose);
        $this->assertNull($copy->team_id);
        $this->assertNotEmpty($copy->slug);
        $this->assertSame($source->fields, $copy->fields);
        $this->assertSame(1, Form::query()->where('purpose', 'registration')->count());
    }

    public function test_repeated_copies_get_distinct_addresses_and_bounded_titles(): void
    {
        $source = Form::factory()->create(['title' => str_repeat('a', 150)]);
        $this->actingAs($source->team->owner)->post(route('forms.duplicate', $source))->assertRedirect();
        $this->post(route('forms.duplicate', $source))->assertRedirect();

        $copies = Form::query()->whereKeyNot($source->id)->get();
        $this->assertCount(2, $copies);
        $this->assertCount(2, $copies->pluck('slug')->unique());
        foreach ($copies as $copy) {
            $this->assertLessThanOrEqual(150, mb_strlen($copy->title));
        }
    }

    #[DataProvider('bannerTypes')]
    public function test_banner_files_are_independent_when_either_form_is_deleted(string $type, string $extension): void
    {
        $source = Form::factory()->create();
        $path = "forms/$source->id/banner.$extension";
        Storage::disk('public')->put($path, 'banner-content');
        $source->forceFill(['media_type' => $type, 'media_path' => $path])->save();
        $this->actingAs($source->team->owner)->post(route('forms.duplicate', $source))->assertRedirect();
        $copy = Form::query()->whereKeyNot($source->id)->sole();
        $this->assertSame($type, $copy->media_type);
        $this->assertNotSame($path, $copy->media_path);
        $this->assertStringStartsWith("forms/$copy->id/", $copy->media_path);
        $this->assertSame('banner-content', Storage::disk('public')->get($copy->media_path));

        $this->delete(route('forms.destroy', $source))->assertRedirect();
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($copy->media_path);
        $this->delete(route('forms.destroy', $copy))->assertRedirect();
        Storage::disk('public')->assertMissing($copy->media_path);
    }

    public static function bannerTypes(): array
    {
        return ['image' => ['image', 'jpg'], 'video' => ['video', 'mp4']];
    }

    public function test_a_missing_banner_rolls_back_the_copy_and_shows_a_retry_message(): void
    {
        $source = Form::factory()->create();
        $source->forceFill(['media_type' => 'image', 'media_path' => "forms/$source->id/missing.jpg"])->save();

        $this->actingAs($source->team->owner)->from(route('forms.index'))->post(route('forms.duplicate', $source))
            ->assertRedirect(route('forms.index'))->assertSessionHasErrors(['duplicate' => 'The banner could not be copied. Please try again.']);

        $this->assertDatabaseCount('forms', 1);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_database_failure_removes_the_copied_file_and_partial_form(): void
    {
        $source = Form::factory()->create();
        $path = "forms/$source->id/banner.jpg";
        Storage::disk('public')->put($path, 'banner-content');
        $source->forceFill(['media_type' => 'image', 'media_path' => $path])->save();
        Form::saving(function (Form $form) use ($source): void {
            if ($form->id !== $source->id && $form->media_path !== null) {
                throw new RuntimeException('Could not save the copied banner.');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($source->team->owner)->post(route('forms.duplicate', $source));
            $this->fail('The copy should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Could not save the copied banner.', $exception->getMessage());
        }

        $this->assertDatabaseCount('forms', 1);
        $this->assertSame([$path], Storage::disk('public')->allFiles());
    }
}
