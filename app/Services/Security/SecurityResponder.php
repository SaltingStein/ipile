<?php

namespace App\Services\Security;

use App\Events\Security\SecurityActionTaken;
use App\Http\Middleware\EnforceSecurityStepUp;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Auth\BrowserSessionRegistry;
use App\Services\Auth\DeviceName;
use App\Services\Auth\TrustedDeviceManager;
use App\Services\Notifications\Notify;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carries out a response from the ladder in {@see SecurityAction}: by Aegis in enforce mode, and by code that records
 * events with a forced score (the site assistant's guard rails). Every response taken is announced with
 * {@see SecurityActionTaken} and runs the builder handlers added with {@see Security::onAction()}.
 */
class SecurityResponder
{
    private const ALERT_COOLDOWN_MINUTES = 60;

    public function __construct(
        private BrowserSessionRegistry $sessions,
        private TrustedDeviceManager $trustedDevices,
        private SecurityEventCatalog $catalog,
        private SecurityResponseHooks $hooks,
    ) {}

    /**
     * @param  'aegis'|'assistant'  $source
     * @return SecurityAction The response actually taken (a step-up that cannot apply becomes an alert).
     */
    public function respond(
        User $user,
        SecurityAction $action,
        SecurityEvent $event,
        ?SecurityIncident $incident = null,
        string $source = SecurityActionTaken::SOURCE_AEGIS,
    ): SecurityAction {
        $taken = match ($action) {
            SecurityAction::None => SecurityAction::None,
            SecurityAction::Notify => $this->alert($user, $event),
            SecurityAction::StepUp => $this->stepUp($user, $event),
            SecurityAction::RevokeSessions => $this->revokeSessions($user, $event),
            SecurityAction::Suspend => $this->suspend($user, $event),
        };

        if ($taken !== SecurityAction::None) {
            $this->announce($user, $taken, $action, $event, $incident, $source);
        }

        return $taken;
    }

    /**
     * Run the builder handlers for a response that was taken, then dispatch {@see SecurityActionTaken}. Neither can
     * undo or interrupt the response: failures are reported and swallowed.
     *
     * @param  'aegis'|'assistant'  $source
     */
    public function announce(
        User $user,
        SecurityAction $taken,
        SecurityAction $requested,
        ?SecurityEvent $event,
        ?SecurityIncident $incident,
        string $source = SecurityActionTaken::SOURCE_AEGIS,
    ): void {
        $announcement = new SecurityActionTaken($user, $taken, $requested, $event, $incident, $source);

        $this->hooks->run($announcement);

        try {
            event($announcement);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function alert(User $user, SecurityEvent $event): SecurityAction
    {
        if (Cache::add('security:alerted:'.$user->getKey(), true, now()->addMinutes(self::ALERT_COOLDOWN_MINUTES))) {
            Notify::event('security.suspicious_activity')->to($user)->with([
                'activity' => $this->catalog->label($event->type),
                'device' => DeviceName::fromUserAgent($event->user_agent),
                'ip_address' => $event->ip_address,
                'link' => route('security.show'),
            ])->send();
        }

        return SecurityAction::Notify;
    }

    /**
     * Ask this browser to confirm the password before continuing. Needs the user's own web session and a password.
     */
    private function stepUp(User $user, SecurityEvent $event): SecurityAction
    {
        if (! $this->isCurrentWebSessionOf($user) || ! $user->hasPassword()) {
            return $this->alert($user, $event);
        }

        request()->session()->put(EnforceSecurityStepUp::SESSION_KEY, now()->getTimestamp());
        $this->alert($user, $event);

        return SecurityAction::StepUp;
    }

    private function revokeSessions(User $user, SecurityEvent $event): SecurityAction
    {
        $this->signOutEverywhere($user);
        $this->alert($user, $event);

        return SecurityAction::RevokeSessions;
    }

    private function suspend(User $user, SecurityEvent $event): SecurityAction
    {
        $user->forceFill(['status' => 'suspended'])->save();
        $this->signOutEverywhere($user);
        $this->alert($user, $event);

        return SecurityAction::Suspend;
    }

    /**
     * End every browser session and remember-me cookie, delete every API token and forget every trusted device.
     */
    public function signOutEverywhere(User $user): void
    {
        $isCurrentSession = $this->isCurrentWebSessionOf($user);

        $this->sessions->revokeAll($user);
        $this->trustedDevices->revokeAll($user);
        $user->tokens()->delete();
        $user->setRememberToken(Str::random(60));
        $user->save();

        if ($isCurrentSession) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
    }

    private function isCurrentWebSessionOf(User $user): bool
    {
        return request()->hasSession() && Auth::guard('web')->id() === $user->getKey();
    }
}
