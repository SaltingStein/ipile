<?php

namespace App\Ai\Insights;

use App\Services\Assistant\AggregateInsight;

class SmsDelivery extends AggregateInsight
{
    public function key(): string
    {
        return 'sms.delivery';
    }

    public function label(): string
    {
        return 'SMS delivery counts';
    }

    public function permission(): ?string
    {
        return 'manage_integrations';
    }

    public function table(): string
    {
        return 'assistant_insight_summaries';
    }

    public function summaryKey(): ?string
    {
        return 'sms.delivery';
    }
}
