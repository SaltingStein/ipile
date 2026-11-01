<?php

namespace App\Http\Middleware;

use App\Models\LegalDocument;
use App\Services\ApiDocs\ApiDocsSettings;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\AssistantWidget;
use App\Services\Auth\AuthenticationSettings;
use App\Services\BrandingSettings;
use App\Services\ConsentSettings;
use App\Services\Integrations\IntegrationManager;
use App\Services\NavigationSettings;
use App\Services\ThemeSettings;
use App\Services\Translation\TranslationSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Exposes a consistent auth payload to the frontend so permission checks can be rendered without extra API calls.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->display_name,
                    'nickname' => $user->nickname,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'is_guest' => $user->is_guest,
                    'email_verified' => $user->hasVerifiedEmail(),
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),

                    // Share role and permission data alongside the user so the UI can gate features immediately.
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->all(),
                    'is_super_admin' => $user->hasRole('super_admin'),
                    'current_team_id' => $user->current_team_id,
                ] : null,
            ],
            // Teams for the switcher in the app shell; guests cannot have teams.
            'teams' => fn () => $user && ! $user->is_guest
                ? $user->allTeams()->map(fn ($team) => ['id' => $team->id, 'name' => $team->name])->values()
                : [],
            'legalLinks' => fn () => LegalDocument::publicLinks(),
            'consent' => fn () => [
                'config' => app(ConsentSettings::class)->config(),
                'choice' => app(ConsentSettings::class)->choice($request),
            ],
            // Public tracking IDs only; the browser loads them once the visitor accepts analytics cookies.
            'analytics' => fn () => [
                'google_measurement_id' => app(IntegrationManager::class)->enabledConfig('google_analytics')['measurement_id'] ?? null,
            ],
            'branding' => fn () => app(BrandingSettings::class)->publicDetails(),
            'theme' => fn () => app(ThemeSettings::class)->current(),
            'canRegister' => fn () => app(AuthenticationSettings::class)->registrationEnabled(),
            'canStartGuest' => fn () => app(AuthenticationSettings::class)->guestAccessEnabled(),
            'aboutBoilerplateUrl' => fn () => app(BrandingSettings::class)->aboutPageVisible()
                ? route('about.boilerplate')
                : null,
            'boilerplateCredit' => fn () => app(BrandingSettings::class)->creditVisible() ? BrandingSettings::BOILERPLATE_NAME : null,
            'navigation' => fn () => $user ? app(NavigationSettings::class)->forUser($user) : [],
            'navigationSettingsUrl' => $user?->can('manage_settings') ? route('admin.navigation.edit') : null,
            // Header links on public pages (Admin → Menus), plus Blog while the blog is switched on.
            'siteMenu' => fn () => app(NavigationSettings::class)->siteMenu(),
            // Languages visitors can switch public pages to (Admin → Languages); null while translation is off.
            'siteLanguages' => fn () => app(TranslationSettings::class)->forVisitors(),
            // Shows the Assistant link in the workspace sidebar for people with a full account.
            'assistantEnabled' => fn () => $user !== null && ! $user->is_guest && $user->can('use_ai_assistant') && app(AssistantSettings::class)->enabled(),
            'assistantWidget' => fn () => app(AssistantWidget::class)->forUser($user),
            // Shows the Developer portal link in the workspace sidebar's Developers section.
            'developerPortalVisible' => fn () => $user !== null && ! $user->is_guest && app(ApiDocsSettings::class)->isVisibleTo($user),
            'status' => fn () => $request->session()->get('status'),
        ]);
    }
}
