<?php

namespace App\Ai\Insights;

use App\Services\Assistant\AggregateInsight;

/**
 * Totals for the whole period, not per day: a daily split multiplies the groups past the row cap and would drop whole
 * statuses from the answer.
 */
class PaymentBreakdown extends AggregateInsight
{
    public function key(): string
    {
        return 'payments.breakdown';
    }

    public function label(): string
    {
        return 'Payments by status, provider and currency';
    }

    public function permission(): ?string
    {
        return 'view_billing';
    }

    public function table(): string
    {
        return 'payments';
    }

    public function sumColumn(): ?string
    {
        return 'amount';
    }

    public function groupBy(): array
    {
        return ['status', 'provider', 'currency'];
    }
}
