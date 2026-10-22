<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Forms\RegistrationForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * New account details for the web and API registration endpoints, plus answers to the published sign-up
 * questions from Admin → Forms (sent as `answers.{key}`).
 */
class RegisterRequest extends FormRequest
{
    /**
     * Registration can be closed from the admin dashboard.
     */
    public function authorize(AuthenticationSettings $settings): bool
    {
        return $settings->registrationEnabled();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ...app(RegistrationForm::class)->rules(),
        ];
    }

    /**
     * Sign-up question labels for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return app(RegistrationForm::class)->attributes();
    }

    /**
     * Validated answers to the sign-up questions (empty when none are published).
     *
     * @return array<string, mixed>
     */
    public function signupAnswers(): array
    {
        return $this->validated('answers') ?? [];
    }
}
