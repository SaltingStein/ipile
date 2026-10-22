<?php

namespace Tests\Feature\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers FormSubmissionController: reading, exporting and deleting a form's responses.
 */
class FormSubmissionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_newest_responses_with_a_column_per_field_and_removed_answers_last(): void
    {
        $form = Form::factory()->create();
        FormSubmission::factory()->for($form)->create(['data' => ['full_name' => 'Older', 'company' => 'Acme'], 'created_at' => now()->subDay()]);
        $newest = FormSubmission::factory()->for($form)->for(User::factory()->create(['email' => 'ada@example.com']))->create();

        $this->actingAs($form->team->owner)->get(route('forms.submissions.index', $form))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Submissions')
                ->where('form.submissions_count', 2)
                ->where('columns.0.key', 'full_name')
                ->where('columns.3', ['key' => 'company', 'label' => 'company', 'type' => 'text', 'removed' => true])
                ->where('submissions.total', 2)
                ->where('submissions.data.0.id', $newest->id)
                ->where('submissions.data.0.user.email', 'ada@example.com')
                ->where('submissions.data.1.data.company', 'Acme'));
    }

    public function test_csv_export_has_one_row_per_response_and_neutralises_formulas(): void
    {
        $form = Form::factory()->create(['title' => 'Summit registration']);
        FormSubmission::factory()->for($form)->create(['data' => ['full_name' => '=HYPERLINK("http://evil")', 'email' => 'ada@example.com', 'ticket' => 'Student'], 'created_at' => '2026-10-19 09:30:00']);

        $response = $this->actingAs($form->team->owner)->get(route('forms.submissions.export', $form));

        $response->assertOk();
        $response->assertDownload('summit-registration-responses-'.now()->format('Y-m-d').'.csv');
        $lines = explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $response->streamedContent())));
        $this->assertSame('"Submitted at","Account email","Full name",Email,Ticket', $lines[0]);
        $this->assertSame('"2026-10-19 09:30:00",,"\'=HYPERLINK(""http://evil"")",ada@example.com,Student', $lines[1]);
    }

    public function test_owner_deletes_a_response(): void
    {
        $form = Form::factory()->create();
        $submission = FormSubmission::factory()->for($form)->create();

        $this->actingAs($form->team->owner)->delete(route('forms.submissions.destroy', [$form, $submission]))
            ->assertSessionHas('status', 'Response deleted.');

        $this->assertModelMissing($submission);
    }

    public function test_response_from_another_form_cannot_be_deleted_through_this_one(): void
    {
        $form = Form::factory()->create();
        $otherSubmission = FormSubmission::factory()->create();

        $this->actingAs($form->team->owner)->delete(route('forms.submissions.destroy', [$form, $otherSubmission]))->assertNotFound();

        $this->assertModelExists($otherSubmission);
    }

    public function test_members_and_outsiders_cannot_read_responses(): void
    {
        $team = Team::factory()->create();
        $form = Form::factory()->for($team)->create();
        $submission = FormSubmission::factory()->for($form)->create();
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => TeamRole::Member->value]);
        $outsider = User::factory()->create();

        $this->actingAs($member)->get(route('forms.submissions.index', $form))->assertForbidden();
        $this->actingAs($member)->get(route('forms.submissions.export', $form))->assertForbidden();
        $this->actingAs($member)->delete(route('forms.submissions.destroy', [$form, $submission]))->assertForbidden();
        $this->actingAs($outsider)->get(route('forms.submissions.index', $form))->assertNotFound();
        $this->actingAs($outsider)->get(route('forms.submissions.export', $form))->assertNotFound();
        $this->assertModelExists($submission);
    }
}
