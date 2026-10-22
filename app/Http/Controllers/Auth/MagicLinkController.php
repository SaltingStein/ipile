<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendMagicLink;
use App\Models\MagicLink;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\SessionAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class MagicLinkController extends Controller
{
    public const SENT_MESSAGE = 'If an eligible account exists for that email, a sign-in link will be sent. Check your inbox and spam folder.';

    public const INVALID_MESSAGE = 'This sign-in link is invalid or expired. Request a new one.';

    public function store(Request $request, AuthenticationSettings $settings): RedirectResponse
    {
        abort_unless($settings->magicLinkEnabled(), 404);
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower(trim($validated['email']));
        $token = Str::random(64);
        $minutes = $settings->magicLinkExpiryMinutes();

        $link = DB::transaction(function () use ($email, $token, $minutes): ?MagicLink {
            $user = User::query()->where('email', $email)->where('is_guest', false)->where('status', 'active')->lockForUpdate()->first();
            if ($user === null) {
                return null;
            }

            MagicLink::query()->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

            return MagicLink::query()->create([
                'user_id' => $user->id, 'email' => $user->email, 'token' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($minutes),
            ]);
        });

        if ($link !== null) {
            try {
                SendMagicLink::dispatch($link->id, URL::temporarySignedRoute('magic-link.verify', $link->expires_at, ['token' => $token]), $minutes);
            } catch (Throwable $exception) {
                $link->update(['used_at' => now()]);
                Log::warning('Magic-link email could not be queued.', ['exception_type' => $exception::class]);
            }
        }

        return redirect()->route('login')->with('status', __(self::SENT_MESSAGE));
    }

    public function show(Request $request, string $token, AuthenticationSettings $settings, SessionAuthenticator $authenticator): RedirectResponse
    {
        abort_unless($settings->magicLinkEnabled(), 404);
        if (! $request->hasValidSignature()) {
            return $this->invalid();
        }

        $user = DB::transaction(function () use ($token): ?User {
            $link = MagicLink::query()->where('token', hash('sha256', $token))->valid()->first();
            if ($link === null) {
                return null;
            }

            $user = User::query()->whereKey($link->user_id)->lockForUpdate()->first();
            if ($user === null || $user->is_guest || $user->isSuspended() || $user->email !== $link->email) {
                return null;
            }

            return MagicLink::query()->whereKey($link->id)->valid()->update(['used_at' => now()]) === 1 ? $user : null;
        });

        return $user === null ? $this->invalid() : $authenticator->login($request, $user, remember: false);
    }

    private function invalid(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['magic_link' => __(self::INVALID_MESSAGE)]);
    }
}
