<?php

namespace App\Services\Account;

use RuntimeException;

/**
 * The account cannot be deleted yet; the message tells the person what to do first.
 */
class AccountDeletionException extends RuntimeException {}
