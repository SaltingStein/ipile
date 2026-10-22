<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Auth\RegisterRequest as WebRegisterRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * API registration: the web fields plus a device name for the issued token.
 */
class RegisterRequest extends WebRegisterRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
