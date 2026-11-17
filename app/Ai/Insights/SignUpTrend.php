<?php

namespace App\Ai\Insights;

use App\Services\Assistant\AggregateInsight;

class SignUpTrend extends AggregateInsight
{
    public function key(): string
    {
        return 'accounts.signups';
    }

    public function label(): string
    {
        return 'Sign-ups over time';
    }

    public function permission(): ?string
    {
        return 'manage_users';
    }

    public function table(): string
    {
        return 'users';
    }

    public function trend(): bool
    {
        return true;
    }

    public function filters(): array
    {
        return ['is_guest' => false];
    }
}
