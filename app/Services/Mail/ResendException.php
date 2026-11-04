<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Resend refused or could not receive an email. The message is Resend's own error text, which does not
 * include credentials.
 */
class ResendException extends TransportException {}
