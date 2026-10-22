<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Raised when an OAuth identity cannot be signed in or linked. The message is safe to show to the user.
 */
class SocialLoginException extends RuntimeException {}
