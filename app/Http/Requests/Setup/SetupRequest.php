<?php

namespace App\Http\Requests\Setup;

use App\Models\User;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * First super admin details from the /setup page, unlocked by the setup key.
 */
class SetupRequest extends FormRequest
{
    /**
     * The page is only open while no super admin exists.
     */
    public function authorize(SuperAdminSetup $setup): bool
    {
        return $setup->isRequired();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'setup_key' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('This email already has an account. Run `php artisan ipile:setup` on the server to make it the super admin.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('setup_key') && ! app(SuperAdminSetup::class)->keyMatches((string) $this->input('setup_key'))) {
                    $validator->errors()->add('setup_key', __('The setup key is not valid. Run `php artisan ipile:setup-key` on the server for a new one.'));
                }
            },
        ];
    }
}
