<?php

namespace Tests\Feature\Auth;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Extra sign-up questions from Admin → Forms on web and API registration (RegisterRequest, RegisterUser).
 */
class SignupQuestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_sign_up_page_shows_published_questions_only(): void
    {
        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('signupFields', []));

        $form = $this->signupQuestions();

        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->has('signupFields', 2)
            ->where('signupFields.0.key', 'company'));

        $form->update(['status' => 'draft']);

        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('signupFields', []));
    }

    public function test_web_registration_requires_published_questions_and_stores_the_answers(): void
    {
        $this->signupQuestions();

        $this->post(route('register.store'), $this->account())
            ->assertSessionHasErrors(['answers.company' => 'The Company field is required.']);
        $this->assertDatabaseCount('users', 0);

        $this->post(route('register.store'), [...$this->account(), 'answers' => ['company' => 'Paystack', 'role' => 'Engineer']])
            ->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $submission = FormSubmission::query()->sole();
        $this->assertSame($user->id, $submission->user_id);
        $this->assertSame(['company' => 'Paystack', 'role' => 'Engineer'], $submission->data);
    }

    public function test_api_registration_validates_and_stores_the_same_answers(): void
    {
        $this->signupQuestions();

        $this->postJson(route('api.v1.auth.register'), [...$this->account(), 'device_name' => 'Pixel', 'answers' => ['company' => 'Paystack', 'role' => 'CEO']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers.role');

        $this->postJson(route('api.v1.auth.register'), [...$this->account(), 'device_name' => 'Pixel', 'answers' => ['company' => 'Paystack']])
            ->assertCreated();

        $this->assertSame(['company' => 'Paystack', 'role' => null], FormSubmission::query()->sole()->data);
    }

    public function test_draft_questions_are_neither_required_nor_stored(): void
    {
        $this->signupQuestions()->update(['status' => 'draft']);

        $this->post(route('register.store'), [...$this->account(), 'answers' => ['company' => 'Ignored']])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('form_submissions', 0);
    }

    private function signupQuestions(): Form
    {
        return Form::factory()->registration()->withFields([
            FormTemplates::field(FormFieldType::ShortText, 'company', 'Company', required: true),
            FormTemplates::field(FormFieldType::Dropdown, 'role', 'Role', options: ['Engineer', 'Designer']),
        ])->create();
    }

    /**
     * @return array<string, string>
     */
    private function account(): array
    {
        return [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];
    }
}
