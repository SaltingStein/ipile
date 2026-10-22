<?php

namespace App\Services\Sms;

use RuntimeException;

/**
 * The SMS provider could not be reached or refused a message. The message is safe to log and show to admins.
 */
class SmsException extends RuntimeException {}
