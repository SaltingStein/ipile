<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * ZeptoMail refused or could not receive an email. The message is ZeptoMail's own error text, which
 * does not include credentials.
 */
class ZeptoMailException extends TransportException {}
