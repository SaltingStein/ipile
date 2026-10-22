<?php

namespace App\Jobs;

use App\Models\MagicLink;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Notifications\Notify;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMagicLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $linkId, public string $url, public int $expiryMinutes) {}

    /**
     * Execute the job.
     */
    public function handle(AuthenticationSettings $settings): void
    {
        if (! $settings->magicLinkEnabled()) {
            return;
        }

        $link = MagicLink::query()->with('user')->valid()->find($this->linkId);
        $user = $link?->user;
        if ($link === null || $user === null || $user->is_guest || $user->isSuspended() || $user->email !== $link->email) {
            return;
        }

        /** Claim delivery before calling the provider, so duplicate jobs cannot send the same email twice. */
        if (MagicLink::query()->whereKey($link->id)->valid()->whereNull('delivery_started_at')->update(['delivery_started_at' => now()]) !== 1) {
            return;
        }

        $minutesRemaining = max(1, (int) ceil(($link->expires_at->timestamp - now()->timestamp) / 60));

        Notify::event('auth.magic_link')->to($user)->with(['link' => $this->url, 'expires_in' => min($this->expiryMinutes, $minutesRemaining)])->send();
    }
}
