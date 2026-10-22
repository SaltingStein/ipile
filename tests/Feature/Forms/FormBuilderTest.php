<?php

namespace Tests\Feature\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Forms\FormStatus;
use App\Services\Teams\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers FormController: the team forms list, creating from templates, the builder and deleting.
 */
class FormBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('forms.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_the_current_teams_forms(): void
    {
        $team = Team::factory()->create();
        $mine = Form::factory()->for($team)->create(['title' => 'Summit registration']);
        Form::factory()->create(['title' => 'Another team']);
        Form::factory()->site()->create(['title' => 'Site form']);

        $this->actingAs($this->currentOwner($team))->get(route('forms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Index')
                ->where('team.id', $team->id)
                ->where('canManage', true)
                ->has('forms', 1)
                ->where('forms.0.id', $mine->id)
                ->where('forms.0.submissions_count', 0)
                ->missing('forms.0.fields'));
    }

    public function test_index_without_a_current_team_shows_no_forms(): void
    {
        $this->actingAs(User::factory()->create())->get(route('forms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('team', null)->where('forms', [])->where('canManage', false));
    }

    public function test_owner_creates_a_draft_from_a_template_and_lands_in_the_builder(): void
    {
        $team = Team::factory()->create();
        $owner = $this->currentOwner($team);

        $response = $this->actingAs($owner)->post(route('forms.store'), ['template' => 'event_registration']);

        $form = Form::query()->sole();
        $response->assertRedirect(route('forms.edit', $form));
        $this->assertSame($team->id, $form->team_id);
        $this->assertSame($owner->id, $form->created_by);
        $this->assertSame(FormStatus::Draft, $form->status);
        $this->assertMatchesRegularExpression('/^event-registration-[a-z0-9]{6}$/', $form->slug);
        $this->assertContains('code_of_conduct', array_column($form->fields, 'key'));

        $this->actingAs($owner)->get(route('forms.edit', $form))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Builder')
                ->where('form.id', $form->id)
                ->has('form.fields', count($form->fields))
                ->has('fieldTypes', 12)
                ->where('backUrl', route('forms.index')));
    }

    public function test_unknown_template_is_rejected(): void
    {
        $team = Team::factory()->create();

        $this->actingAs($this->currentOwner($team))->post(route('forms.store'), ['template' => 'nope'])
            ->assertSessionHasErrors('template');
        $this->assertDatabaseCount('forms', 0);
    }

    public function test_team_member_sees_forms_but_cannot_create_or_edit_them(): void
    {
        $team = Team::factory()->create();
        $form = Form::factory()->for($team)->create();
        $member = $this->addMember($team, TeamRole::Member);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($member)->get(route('forms.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('forms', 1));
        $this->actingAs($member)->post(route('forms.store'), ['template' => 'blank'])->assertForbidden();
        $this->actingAs($member)->get(route('forms.edit', $form))->assertForbidden();
        $this->actingAs($member)->put(route('forms.update', $form), $this->payload())->assertForbidden();
        $this->actingAs($member)->delete(route('forms.destroy', $form))->assertForbidden();
    }

    public function test_someone_outside_the_team_gets_not_found(): void
    {
        $form = Form::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('forms.edit', $form))->assertNotFound();
        $this->actingAs($outsider)->put(route('forms.update', $form), $this->payload())->assertNotFound();
        $this->assertSame($form->title, $form->fresh()->title);
    }

    public function test_team_admin_saves_settings_and_fields_in_order_with_unused_settings_cleared(): void
    {
        $team = Team::factory()->create();
        $form = Form::factory()->for($team)->draft()->create();
        $admin = $this->addMember($team, TeamRole::Admin);

        $payload = $this->payload([
            'title' => 'Lagos Tech Summit',
            'slug' => 'lagos-tech-summit',
            'status' => 'published',
            'max_submissions' => 250,
            'closes_at' => '2026-12-01T17:00:00.000Z',
            'sends_confirmation' => true,
            'fields' => [
                $this->field(['id' => 'f_b', 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'options' => ['ignored']]),
                $this->field(['id' => 'f_a', 'key' => 'about', 'type' => 'heading', 'label' => 'About you', 'required' => true]),
                $this->field(['id' => 'f_c', 'key' => 'track', 'type' => 'radio', 'label' => 'Track', 'options' => [' Design ', 'Engineering'], 'min' => 1]),
            ],
        ]);

        $this->actingAs($admin)->put(route('forms.update', $form), $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Saved. The form is live.');

        $form->refresh();
        $this->assertSame('Lagos Tech Summit', $form->title);
        $this->assertSame('lagos-tech-summit', $form->slug);
        $this->assertSame(FormStatus::Published, $form->status);
        $this->assertSame(250, $form->max_submissions);
        $this->assertSame('2026-12-01 17:00:00', $form->closes_at->utc()->toDateTimeString());
        $this->assertTrue($form->sends_confirmation);
        $this->assertSame(['email', 'about', 'track'], array_column($form->fields, 'key'));
        $this->assertSame([], $form->fields[0]['options']);
        $this->assertFalse($form->fields[1]['required']);
        $this->assertSame(['Design', 'Engineering'], $form->fields[2]['options']);
        $this->assertNull($form->fields[2]['min']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidBuilderPayloads')]
    public function test_builder_rejects_invalid_forms(array $overrides, string $errorKey): void
    {
        $team = Team::factory()->create();
        Form::factory()->create(['slug' => 'taken']);
        $form = Form::factory()->for($team)->draft()->create();

        $this->actingAs($team->owner)->put(route('forms.update', $form), $this->payload($overrides))
            ->assertSessionHasErrors($errorKey);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBuilderPayloads(): array
    {
        $field = fn (array $values) => [
            'id' => 'f_1', 'key' => 'answer', 'type' => 'text', 'label' => 'Answer', 'hint' => null,
            'placeholder' => null, 'required' => false, 'options' => [], 'min' => null, 'max' => null, ...$values,
        ];

        return [
            'choice without options' => [['fields' => [$field(['type' => 'select'])]], 'fields.0.options'],
            'duplicate options' => [['fields' => [$field(['type' => 'radio', 'options' => ['Yes', 'yes']])]], 'fields.0.options'],
            'duplicate field ids' => [['fields' => [$field([]), $field(['id' => 'f_2'])]], 'fields.1.key'],
            'field id with spaces' => [['fields' => [$field(['key' => 'Job Title'])]], 'fields.0.key'],
            'unknown field type' => [['fields' => [$field(['type' => 'signature'])]], 'fields.0.type'],
            'unexpected field setting' => [['fields' => [[...$field([]), 'script' => 'x']]], 'fields.0'],
            'minimum above maximum' => [['fields' => [$field(['min' => 10, 'max' => 2])]], 'fields.0.max'],
            'publishing without questions' => [['status' => 'published', 'fields' => [$field(['type' => 'heading'])]], 'fields'],
            'confirmation without an email field' => [['sends_confirmation' => true, 'fields' => [$field([])]], 'sends_confirmation'],
            'address already taken' => [['slug' => 'taken'], 'slug'],
            'address with spaces' => [['slug' => 'my form'], 'slug'],
            'too many fields' => [['fields' => array_map(fn (int $index) => $field(['id' => "f_{$index}", 'key' => "q_{$index}"]), range(1, 61))], 'fields'],
        ];
    }

    public function test_owner_deletes_a_form_with_its_responses(): void
    {
        $team = Team::factory()->create();
        $form = Form::factory()->for($team)->create();
        FormSubmission::factory()->for($form)->count(2)->create();

        $this->actingAs($team->owner)->delete(route('forms.destroy', $form))
            ->assertRedirect(route('forms.index'));

        $this->assertModelMissing($form);
        $this->assertDatabaseCount('form_submissions', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Form',
            'description' => null,
            'slug' => 'my-form',
            'status' => 'draft',
            'submit_label' => null,
            'success_message' => null,
            'max_submissions' => null,
            'closes_at' => null,
            'sends_confirmation' => false,
            'fields' => [$this->field()],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function field(array $overrides = []): array
    {
        return [
            'id' => 'f_1', 'key' => 'answer', 'type' => 'text', 'label' => 'Answer', 'hint' => null,
            'placeholder' => null, 'required' => false, 'options' => [], 'min' => null, 'max' => null, ...$overrides,
        ];
    }

    private function currentOwner(Team $team): User
    {
        $owner = $team->owner;
        $owner->forceFill(['current_team_id' => $team->id])->save();

        return $owner;
    }

    private function addMember(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
