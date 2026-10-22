<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Forms\RegistrationForm;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * Creates a registered account, upgrading the current guest in place so their history is kept.
 */
class RegisterUser
{
    public function __construct(private RegistrationForm $registrationForm) {}

    /**
     * @param  array{name: string, email: string, password: string}  $input
     * @param  array<string, mixed>  $answers  validated answers to the sign-up questions (Admin → Forms)
     */
    public function register(array $input, ?User $currentUser, array $answers = []): User
    {
        $attributes = [
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'is_guest' => false,
            'status' => 'active',
        ];

        $user = DB::transaction(function () use ($attributes, $currentUser, $answers) {
            $user = $currentUser?->is_guest
                ? tap($currentUser)->update($attributes)
                : User::create($attributes);

            $this->registrationForm->record($user, $answers);

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
