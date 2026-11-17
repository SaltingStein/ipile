<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User;

class PendingOAuthProfile
{
    public const SESSION_KEY = 'oauth.pending_profile';

    public function store(Request $request, string $provider, SocialiteUser $identity): void
    {
        $raw = method_exists($identity, 'getRaw') ? $identity->getRaw() : [];

        $request->session()->put(self::SESSION_KEY, [
            'provider' => $provider,
            'expires_at' => now()->addMinutes(10)->timestamp,
            'guest_id' => $request->user()?->getKey(),
            'id' => (string) $identity->getId(),
            'name' => $identity->getName() ?: $identity->getNickname(),
            'email' => $identity->getEmail(),
            'avatar' => $identity->getAvatar(),
            'raw' => array_intersect_key($raw, array_flip(['email_verified', 'verified_email', 'confirmed_email'])),
        ]);
    }

    /**
     * @return array{provider: string, identity: User}|null
     */
    public function get(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp
            || ($pending['guest_id'] ?? null) !== $request->user()?->getKey()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return [
            'provider' => $pending['provider'],
            'identity' => (new User)->setRaw($pending['raw'])->map(array_intersect_key($pending, array_flip(['id', 'name', 'email', 'avatar']))),
        ];
    }
}
