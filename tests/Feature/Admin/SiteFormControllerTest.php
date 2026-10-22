<?php

namespace Tests\Feature\Admin;

use App\Models\Form;
use App\Models\Team;
use App\Models\User;
use App\Services\Forms\FormStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers SiteFormController and FormPolicy for site forms and the sign-up questions.
 */
class SiteFormControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_without_manage_forms_cannot_open_site_forms(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->givePermissionTo('manage_settings');
        $siteForm = Form::factory()->site()->create();

        $this->actingAs($user)->get(route('admin.forms.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.forms.store'), ['template' => 'contact'])->assertForbidden();
        $this->actingAs($user)->get(route('forms.edit', $siteForm))->assertForbidden();
        $this->actingAs($user)->get(route('forms.submissions.index', $siteForm))->assertForbidden();
    }

    public function test_team_owner_cannot_edit_a_site_form(): void
    {
        $team = Team::factory()->create();
        $siteForm = Form::factory()->site()->create();

        $this->actingAs($team->owner)->get(route('forms.edit', $siteForm))->assertForbidden();
    }

    public function test_form_manager_sees_site_forms_with_the_sign_up_questions_first(): void
    {
        $manager = $this->manager();
        $siteForm = Form::factory()->site()->create();
        Form::factory()->create();

        $this->actingAs($manager)->get(route('admin.forms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Forms')
                ->has('forms', 2)
                ->where('forms.0.is_registration', true)
                ->where('forms.0.status', 'draft')
                ->where('forms.0.public_url', null)
                ->where('forms.1.id', $siteForm->id)
                ->has('templates', 4));
    }

    public function test_form_manager_creates_a_site_form_and_edits_it_in_the_admin_area(): void
    {
        $manager = $this->manager();

        $response = $this->actingAs($manager)->post(route('admin.forms.store'), ['template' => 'contact']);

        $form = Form::query()->sole();
        $response->assertRedirect(route('forms.edit', $form));
        $this->assertNull($form->team_id);
        $this->actingAs($manager)->get(route('forms.edit', $form))
            ->assertInertia(fn (Assert $page) => $page->where('form.team', null)->where('backUrl', route('admin.forms.index')));
    }

    public function test_sign_up_questions_save_without_public_settings_and_cannot_be_closed(): void
    {
        $manager = $this->manager();
        $form = Form::factory()->registration()->draft()->create();
        $fields = [[
            'id' => 'f_1', 'key' => 'company', 'type' => 'text', 'label' => 'Company', 'hint' => null,
            'placeholder' => null, 'required' => true, 'options' => [], 'min' => null, 'max' => null,
        ]];

        $this->actingAs($manager)->put(route('forms.update', $form), ['title' => 'Sign-up', 'status' => 'closed', 'fields' => $fields])
            ->assertSessionHasErrors('status');

        $this->actingAs($manager)->put(route('forms.update', $form), [
            'title' => 'Sign-up', 'status' => 'published', 'fields' => $fields,
            'slug' => 'hijack', 'max_submissions' => 1, 'sends_confirmation' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved. These questions now appear on the sign-up page.');

        $form->refresh();
        $this->assertSame(FormStatus::Published, $form->status);
        $this->assertNull($form->slug);
        $this->assertNull($form->max_submissions);
        $this->assertFalse($form->sends_confirmation);
    }

    public function test_sign_up_questions_cannot_be_deleted_even_by_a_super_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $superAdmin = User::factory()->create()->assignRole('super_admin');
        $form = Form::factory()->registration()->create();

        $this->actingAs($superAdmin)->delete(route('forms.destroy', $form))->assertForbidden();

        $this->assertModelExists($form);
    }

    public function test_sign_up_questions_have_no_public_page(): void
    {
        Form::factory()->registration()->create();

        $this->get('/f/')->assertNotFound();
        $this->assertSame(1, Form::query()->whereNull('slug')->count());
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_forms');
    }
}
