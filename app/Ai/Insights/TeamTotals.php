<?php

namespace App\Ai\Insights;

use App\Services\Assistant\AggregateInsight;

class TeamTotals extends AggregateInsight
{
    public function key(): string
    {
        return 'teams.created';
    }

    public function label(): string
    {
        return 'Teams created';
    }

    public function permission(): ?string
    {
        return 'manage_users';
    }

    public function table(): string
    {
        return 'teams';
    }
}
