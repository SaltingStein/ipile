<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Team forms follow the team's roles (owner and admins, see {@see TeamPolicy::manageForms()}); site forms,
 * including the sign-up form, need the `manage_forms` permission.
 */
class FormPolicy
{
    public function __construct(private TeamPolicy $teams) {}

    /**
     * Edit the form in the builder and read, export or delete its responses.
     */
    public function update(User $user, Form $form): Response
    {
        if ($form->isSiteForm()) {
            return $user->can('manage_forms')
                ? Response::allow()
                : Response::deny(__('You do not have permission to manage site forms.'));
        }

        return $this->teams->manageForms($user, $form->team);
    }

    /**
     * Same people as {@see self::update()}, except that the sign-up form is never deleted.
     */
    public function delete(User $user, Form $form): Response
    {
        if ($form->isRegistrationForm()) {
            return Response::deny(__('The sign-up form cannot be deleted. Unpublish it to remove its questions from the sign-up page.'));
        }

        return $this->update($user, $form);
    }
}
