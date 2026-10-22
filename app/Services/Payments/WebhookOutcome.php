<?php

namespace App\Services\Payments;

/**
 * What happened when a verified webhook was applied to the ledger.
 */
enum WebhookOutcome: string
{
    case Applied = 'applied';
    case AlreadyProcessed = 'already_processed';
    case AmountMismatch = 'amount_mismatch';
}
