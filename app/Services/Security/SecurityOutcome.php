<?php

namespace App\Services\Security;

/**
 * What a {@see SecurityDecision} says about a risky action.
 *
 * @api
 */
enum SecurityOutcome: string
{
    /** Go ahead. */
    case Allow = 'allow';

    /** Go ahead after a fresh code from the authenticator app. */
    case Challenge = 'challenge';

    /** Do not go ahead. */
    case Deny = 'deny';
}
