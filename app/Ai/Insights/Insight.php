<?php

namespace App\Ai\Insights;

use Laravel\Ai\Contracts\Tool;

interface Insight extends Tool
{
    public function key(): string;

    public function label(): string;

    public function permission(): ?string;

    public function scope(): string;
}
