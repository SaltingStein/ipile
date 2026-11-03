<?php

namespace App\Providers;

use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Ai\AiModels;
use App\Services\Ai\DashboardAiManager;
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\AssistantExtensions;
use App\Services\Assistant\AssistantToolLog;
use App\Services\Auth\ApiTokens;
use App\Services\Integrations\IntegrationManager;
use App\Services\Mail\DashboardMailTransport;
use App\Services\Mail\ZeptoMailTransport;
use App\Services\Notifications\NotificationCatalog;
use App\Services\RateLimits;
use App\Services\Security\Analysis\AiSecurityAnalyst;
use App\Services\Security\Analysis\SecurityAnalyst;
use App\Services\Security\SecurityEventCatalog;
use App\Services\Security\SecurityExportPayload;
use App\Services\Security\SecurityMonitor;
use App\Services\Security\SecurityResponseHooks;
use App\Services\Security\Signals\SecuritySignals;
use App\Services\Sms\SmppTransport;
use App\Services\Sms\SmsTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsTransport::class, SmppTransport::class);

        // Aegis: singletons so events, signals, response handlers and export settings added through Security:: last for the request.
        $this->app->singleton(SecurityEventCatalog::class);
        $this->app->singleton(SecuritySignals::class);
        $this->app->singleton(SecurityMonitor::class);
        $this->app->singleton(SecurityResponseHooks::class);
        $this->app->singleton(SecurityExportPayload::class);
        // Site assistant extensions: AssistantDataPolicy::block(), AssistantMetrics::define(), AssistantTools::register(), AssistantGuard::rule().
        $this->app->singleton(AssistantExtensions::class);
        // Collects the assistant's tool calls for the interaction log while one request is answered.
        $this->app->singleton(AssistantToolLog::class);
        $this->app->bind(SecurityAnalyst::class, AiSecurityAnalyst::class);

        // Agents prompted without a provider use the AI model chosen in the dashboard, never the package's .env keys.
        $this->app->singleton(AiManager::class, fn ($app): AiManager => new DashboardAiManager($app));
        config(['ai.default' => AiModels::DEFAULT_NAME]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function () {
            $rule = Password::min(8)->letters()->numbers();

            return $this->app->isProduction()
                ? $rule->mixedCase()->uncompromised()
                : $rule;
        });

        RateLimits::register();

        // API tokens unused for longer than the dashboard idle timeout stop working (lifetime is set as expires_at).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && ! $this->app->make(ApiTokens::class)->isIdle($token),
        );

        // The site assistant's hard limit: while its tools run, queries on protected tables and columns are refused
        // before they reach the database. A no-op at any other time.
        DB::beforeExecuting(fn (string $query) => AssistantDataPolicy::inspect($query));

        // Super admins pass every permission check, including Spatie's `permission:` middleware.
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);

        // Laravel's own verification and reset emails use the wording from Admin → Notifications.
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => EventNotification::mailMessage(
            app(NotificationCatalog::class)->find('auth.verify_email'),
            EventNotification::withCommonData(['link' => $url, 'expires_in' => config('auth.verification.expire', 60)], $notifiable),
        ));

        ResetPassword::toMailUsing(fn (object $notifiable, string $token): MailMessage => EventNotification::mailMessage(
            app(NotificationCatalog::class)->find('auth.password_reset'),
            EventNotification::withCommonData([
                'link' => ResetPassword::$createUrlCallback
                    ? call_user_func(ResetPassword::$createUrlCallback, $notifiable, $token)
                    : url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false)),
                'expires_in' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ], $notifiable),
        ));

        // The `dashboard` mailer sends through the provider enabled in Admin → Integrations, else the fallback mailer.
        Mail::extend('dashboard', function (array $config): DashboardMailTransport {
            $fallback = $config['fallback'] ?? 'log';

            if ($fallback === 'dashboard') {
                throw new InvalidArgumentException('The dashboard mailer cannot fall back to itself.');
            }

            return new DashboardMailTransport(
                $this->app->make(IntegrationManager::class),
                $this->app->make(ZeptoMailTransport::class),
                Mail::mailer($fallback)->getSymfonyTransport(),
            );
        });
    }
}
