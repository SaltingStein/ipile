<?php

namespace App\Ai\Insights;

use App\Services\Assistant\AggregateInsight;

class SecurityIncidents extends AggregateInsight
{
    public function key(): string
    {
        return 'security.incidents';
    }

    public function label(): string
    {
        return 'Security incident counts';
    }

    public function permission(): ?string
    {
        return 'view_security';
    }

    public function table(): string
    {
        return 'assistant_insight_summaries';
    }

    public function summaryKey(): ?string
    {
        return 'security.incidents';
    }
}
