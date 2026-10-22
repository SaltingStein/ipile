<?php

namespace Tests\Feature\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers PublicFormController and FormSubmissionRecorder: filling in a published form at /f/{slug}.
 */
class PublicFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_form_is_open_to_visitors(): void
    {
        $form = Form::factory()->create(['title' => 'Summit registration', 'submit_label' => 'Reserve my seat']);

        $this->get(route('forms.public.show', $form->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Show')
                ->where('form.title', 'Summit registration')
                ->where('form.submit_label', 'Reserve my seat')
                ->has('form.fields', 3)
                ->where('closedReason', null)
                ->where('successMessage', null));
    }

    public function test_draft_form_is_not_found(): void
    {
        $form = Form::factory()->draft()->create();

        $this->get(route('forms.public.show', $form->slug))->assertNotFound();
        $this->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Ada']])->assertNotFound();
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_valid_answers_are_stored_tidied_and_thanked(): void
    {
        $form = Form::factory()->withFields($this->everyFieldType())->create(['success_message' => 'See you in Lagos!']);

        $response = $this->post(route('forms.public.store', $form->slug), ['answers' => [
            'full_name' => 'Adébáyọ̀ Okafor',
            'email' => 'ada@example.com',
            'phone' => '+234 803 123 4567',
            'guests' => '2',
            'arrival' => '2026-12-01',
            'website' => 'https://example.com',
            'ticket' => 'Student',
            'days' => ['Day 2', 'Day 1'],
            'agree' => '1',
            'not_a_field' => 'dropped',
        ]]);

        $response->assertRedirect(route('forms.public.show', $form->slug));
        $this->assertSame([
            'full_name' => 'Adébáyọ̀ Okafor',
            'email' => 'ada@example.com',
            'phone' => '+234 803 123 4567',
            'guests' => 2,
            'arrival' => '2026-12-01',
            'website' => 'https://example.com',
            'ticket' => 'Student',
            'days' => ['Day 2', 'Day 1'],
            'agree' => true,
        ], FormSubmission::query()->sole()->data);
        $this->assertNull(FormSubmission::query()->sole()->user_id);

        $this->get(route('forms.public.show', $form->slug))
            ->assertInertia(fn (Assert $page) => $page->where('successMessage', 'See you in Lagos!'));
    }

    public function test_answers_are_checked_against_the_saved_fields(): void
    {
        $form = Form::factory()->withFields($this->everyFieldType())->create();

        $this->post(route('forms.public.store', $form->slug), ['answers' => [
            'email' => 'not-an-email',
            'phone' => 'call me',
            'guests' => '99',
            'arrival' => '01/12/2026',
            'website' => 'javascript:alert(1)',
            'ticket' => 'VIP',
            'days' => ['Day 3'],
        ]])->assertSessionHasErrors([
            'answers.full_name' => 'The Full name field is required.',
            'answers.email',
            'answers.phone',
            'answers.guests',
            'answers.arrival',
            'answers.website',
            'answers.ticket',
            'answers.days.0',
            'answers.agree',
        ]);

        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_signed_in_person_is_linked_to_their_response(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create();

        $this->actingAs($user)->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Ada']])->assertSessionHasNoErrors();

        $this->assertSame($user->id, FormSubmission::query()->sole()->user_id);
    }

    public function test_closed_form_shows_a_notice_and_refuses_answers(): void
    {
        $form = Form::factory()->closed()->create();

        $this->get(route('forms.public.show', $form->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('closedReason', 'This form is closed and no longer accepts responses.'));
        $this->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Ada']])
            ->assertSessionHasErrors(['form' => 'This form is closed and no longer accepts responses.']);
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_form_past_its_closing_time_refuses_answers(): void
    {
        $form = Form::factory()->create(['closes_at' => now()->subMinute()]);

        $this->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Ada']])
            ->assertSessionHasErrors(['form' => 'This form is closed and no longer accepts responses.']);
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_form_stops_at_its_response_limit(): void
    {
        $form = Form::factory()->create(['max_submissions' => 2]);
        FormSubmission::factory()->for($form)->create();

        $this->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Last seat']])->assertSessionHasNoErrors();
        $this->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Too late']])
            ->assertSessionHasErrors(['form' => 'This form has reached its response limit.']);

        $this->assertSame(2, $form->submissions()->count());
    }

    public function test_spam_trap_answers_are_thanked_but_not_stored(): void
    {
        $form = Form::factory()->create();

        $this->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Bot'], 'hp_check' => 'https://spam.example'])
            ->assertRedirect(route('forms.public.show', $form->slug))
            ->assertSessionHas('form_submitted', $form->id);

        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_confirmation_is_emailed_to_the_email_answer_when_switched_on(): void
    {
        Notification::fake();
        $form = Form::factory()->create(['title' => 'Summit', 'sends_confirmation' => true, 'success_message' => 'Your seat is reserved.']);

        $this->post(route('forms.public.store', $form->slug), ['answers' => ['full_name' => 'Ada', 'email' => 'ada@example.com']]);

        Notification::assertSentOnDemand(
            EventNotification::class,
            fn (EventNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notification->event === 'forms.submission_received'
                && $notifiable->routes['mail'] === 'ada@example.com'
                && $notification->data['form'] === 'Summit'
                && $notification->data['message'] === 'Your seat is reserved.',
        );
    }

    public function test_no_confirmation_when_switched_off_or_no_email_was_given(): void
    {
        Notification::fake();
        $silent = Form::factory()->create();
        $optional = Form::factory()->create(['sends_confirmation' => true]);

        $this->post(route('forms.public.store', $silent->slug), ['answers' => ['full_name' => 'Ada', 'email' => 'ada@example.com']]);
        $this->post(route('forms.public.store', $optional->slug), ['answers' => ['full_name' => 'Ada']]);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('form_submissions', 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function everyFieldType(): array
    {
        $guests = FormTemplates::field(FormFieldType::Number, 'guests', 'Guests');

        return [
            FormTemplates::field(FormFieldType::Heading, 'intro', 'About you'),
            FormTemplates::field(FormFieldType::ShortText, 'full_name', 'Full name', required: true),
            FormTemplates::field(FormFieldType::Email, 'email', 'Email'),
            FormTemplates::field(FormFieldType::Phone, 'phone', 'Phone'),
            [...$guests, 'min' => 0, 'max' => 5],
            FormTemplates::field(FormFieldType::Date, 'arrival', 'Arrival'),
            FormTemplates::field(FormFieldType::Url, 'website', 'Website'),
            FormTemplates::field(FormFieldType::Dropdown, 'ticket', 'Ticket', options: ['Standard', 'Student']),
            FormTemplates::field(FormFieldType::MultipleChoice, 'days', 'Days', options: ['Day 1', 'Day 2']),
            FormTemplates::field(FormFieldType::Checkbox, 'agree', 'I agree', required: true),
        ];
    }
}
