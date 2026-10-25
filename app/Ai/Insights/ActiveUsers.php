<?php

namespace App\Ai\Insights;

use App\Services\Assistant\AggregateInsight;

class ActiveUsers extends AggregateInsight
{
    public function key(): string
    {
        return 'accounts.active';
    }

    public function label(): string
    {
        return 'Active users';
    }

    public function permission(): ?string
    {
        return 'manage_users';
    }

    public function table(): string
    {
        return 'users';
    }

    public function periodColumn(): string
    {
        return 'last_login_at';
    }

    public function filters(): array
    {
        return ['is_guest' => false, 'status' => 'active'];
    }
}
