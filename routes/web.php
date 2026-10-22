<?php

use App\Http\Controllers\AboutBoilerplateController;
use App\Http\Controllers\Admin\AccessControlController;
use App\Http\Controllers\Admin\ApiDocsSettingsController;
use App\Http\Controllers\Admin\AssistantSettingsController;
use App\Http\Controllers\Admin\AuthenticationSettingsController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\BlogPostController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CampaignPageController;
use App\Http\Controllers\Admin\ConsentSettingsController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LegalContentController;
use App\Http\Controllers\Admin\NavigationController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\OAuthProviderController;
use App\Http\Controllers\Admin\SecurityCenterController;
use App\Http\Controllers\Admin\SecurityGuideController;
use App\Http\Controllers\Admin\SecurityIncidentController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\SiteFormController;
use App\Http\Controllers\Admin\ThemeSettingsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantWidgetClaimController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\ConfirmTwoFactorController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DeveloperPortalController;
use App\Http\Controllers\Forms\FormController;
use App\Http\Controllers\Forms\FormMediaController;
use App\Http\Controllers\Forms\FormSubmissionController;
use App\Http\Controllers\Forms\PublicFormController;
use App\Http\Controllers\HelpCenterController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\BrowserSessionController;
use App\Http\Controllers\Settings\ConnectedAccountController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\RecoveryCodeController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TrustedDeviceController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\Teams\CurrentTeamController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Controllers\Teams\TeamOwnershipController;
use App\Http\Controllers\TranslationController;
use App\Http\Middleware\EnsureApiDocsAreVisible;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/legal/{document}', [LegalPageController::class, 'show'])->name('legal.show');
Route::post('/consent', [ConsentController::class, 'store'])->middleware('throttle:consent')->name('consent.store');

// First-run setup: creates the super admin with the key from the server log or `php artisan ipile:setup-key`.
Route::get('/setup', [SetupController::class, 'create'])->name('setup.create');
Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:setup')->name('setup.store');

// Landing page for the selected product layout.
Route::get('/', [LandingPageController::class, 'index'])->name('home');
Route::get('/about-boilerplate', [AboutBoilerplateController::class, 'show'])->name('about.boilerplate');

// Same visibility as the generated API docs (Admin → API documentation).
Route::get('/developers', [DeveloperPortalController::class, 'show'])->middleware(EnsureApiDocsAreVisible::class)->name('developers');

// The blog (Admin → Blog). Its pages return 404 while the blog is switched off, except previews for blog managers.
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/feed', [BlogController::class, 'feed'])->name('blog.feed');
Route::get('/blog/category/{category:slug}', [BlogController::class, 'category'])->name('blog.category');
Route::get('/blog/{post:slug}', [BlogController::class, 'show'])->name('blog.show');

// Campaign landing pages (Admin → Campaigns). Drafts are only shown to campaign managers, as a preview.
Route::get('/c/{campaign:slug}', [CampaignController::class, 'show'])->name('campaigns.show');
Route::post('/c/{campaign:slug}/form', [CampaignController::class, 'submit'])->middleware('throttle:form-submissions')->name('campaigns.submit');
// Sent with navigator.sendBeacon, which cannot add the CSRF header; it only adds one to a click counter.
Route::post('/c/{campaign:slug}/click', [CampaignController::class, 'click'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:campaign-clicks')
    ->name('campaigns.click');

// Translations for public pages while translation is on (Admin → Languages).
Route::get('/translations/{locale}', [TranslationController::class, 'dictionary'])->where('locale', '[a-z]{2,3}')->name('translations.dictionary');
Route::post('/translations/missing', [TranslationController::class, 'missing'])->middleware('throttle:translation-reports')->name('translations.missing');

// Published forms from the form builder, open to anyone. Drafts are not found.
Route::get('/f/{form:slug}', [PublicFormController::class, 'show'])->name('forms.public.show');
Route::post('/f/{form:slug}', [PublicFormController::class, 'store'])->middleware('throttle:form-submissions')->name('forms.public.store');

// Guest onboarding flows should start from a lightweight, friction-free form.
Route::post('/guest/start', [OnboardingController::class, 'startGuestSession'])
    ->name('guest.start');

// Sign-in and sign-up pages. Guest accounts may use them to register or switch to a real account.
Route::middleware('redirect.registered')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register')->name('register.store');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::post('/auth/magic-link', [MagicLinkController::class, 'store'])->middleware('throttle:magic-link')->name('magic-link.request');
    Route::get('/auth/magic-link/{token}', [MagicLinkController::class, 'show'])->name('magic-link.verify');

    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.store');
});

// Social sign-in. Registered users who start this flow link the provider to their account instead.
Route::get('/auth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Help Center: Markdown articles from resources/help, filtered by permission. Open before two-factor setup so users can read how.
    Route::get('/help', [HelpCenterController::class, 'index'])->name('help.index');
    Route::get('/help/{slug}', [HelpCenterController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('help.show');

    // Account features that need a real (non-guest) account.
    Route::middleware('registered')->group(function () {
        Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:verification'])
            ->name('verification.verify');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:verification')
            ->name('verification.send');

        Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
        Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])
            ->middleware('throttle:password-confirm')
            ->name('password.confirm.store');

        // A fresh authenticator code before protected actions (the security.confirm middleware and Security::check()).
        Route::get('/security/confirm', [ConfirmTwoFactorController::class, 'show'])->name('security.confirm');
        Route::post('/security/confirm', [ConfirmTwoFactorController::class, 'store'])
            ->middleware('throttle:password-confirm')
            ->name('security.confirm.store');

        Route::middleware('password.confirm')->prefix('settings')->group(function () {
            Route::get('/security', [SecurityController::class, 'show'])->name('security.show');

            Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
            Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
            Route::delete('/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])
                ->whereNumber('tokenId')
                ->middleware('security.track:api.token_revoked')
                ->name('api-tokens.destroy');
            Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

            Route::post('/two-factor', [TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
            Route::post('/two-factor/confirm', [TwoFactorAuthenticationController::class, 'confirm'])->name('two-factor.confirm');
            Route::delete('/two-factor', [TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
            Route::post('/two-factor/recovery-codes', [RecoveryCodeController::class, 'store'])->name('two-factor.recovery-codes');

            Route::delete('/browser-sessions', [BrowserSessionController::class, 'destroy'])->middleware('security.track:account.sessions_revoked')->name('browser-sessions.destroy');
            Route::delete('/trusted-devices', [TrustedDeviceController::class, 'destroy'])->name('trusted-devices.destroy');
            Route::delete('/connected-accounts/{provider}', [ConnectedAccountController::class, 'destroy'])->name('connected-accounts.destroy');
        });
    });

    // Members of roles that require two-factor authentication must set it up before using the app.
    // The dashboard itself lives in routes/app.php with the rest of your app's pages.
    Route::middleware('two-factor.configured')->group(function () {
        // Admin overview: open to anyone who can reach at least one admin page (see resources/js/adminNavigation.ts).
        Route::get('/admin', fn () => Inertia::render('Admin/Overview'))
            ->middleware('permission:manage_settings|edit_legal_content|publish_legal_content|manage_access|manage_integrations|view_security|manage_security|manage_forms|manage_blog|manage_campaigns|manage_translations')
            ->name('admin.index');
        Route::permanentRedirect('/admin/site-settings', '/admin');

        Route::middleware('permission:edit_legal_content|publish_legal_content')->group(function () {
            Route::get('/admin/legal', [LegalContentController::class, 'index'])->name('admin.legal.index');
            Route::get('/admin/legal/{document}', [LegalContentController::class, 'edit'])->name('admin.legal.edit');
            Route::put('/admin/legal/{document}', [LegalContentController::class, 'update'])
                ->middleware('permission:edit_legal_content')->name('admin.legal.update');
            Route::post('/admin/legal/{document}/publish', [LegalContentController::class, 'publish'])
                ->middleware('permission:publish_legal_content')->name('admin.legal.publish');
        });
        Route::middleware('permission:manage_settings|edit_legal_content')->group(function () {
            Route::get('/admin/consent', [ConsentSettingsController::class, 'edit'])->name('admin.consent.edit');
            Route::put('/admin/consent', [ConsentSettingsController::class, 'update'])->name('admin.consent.update');
        });

        Route::middleware('permission:manage_access')->group(function () {
            Route::get('/admin/access', [AccessControlController::class, 'index'])->name('admin.access');
            Route::post('/admin/access', [AccessControlController::class, 'updateRole'])->middleware('security.track:admin.access_changed')->name('admin.access.update');
        });

        Route::middleware('permission:manage_settings')->group(function () {
            Route::get('/admin/branding', [BrandingController::class, 'edit'])->name('admin.branding.edit');
            Route::post('/admin/branding', [BrandingController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.branding.update');
            Route::get('/admin/theme', [ThemeSettingsController::class, 'edit'])->name('admin.theme.edit');
            Route::put('/admin/theme', [ThemeSettingsController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.theme.update');
            Route::get('/admin/notifications', [NotificationSettingsController::class, 'index'])->name('admin.notifications.index');
            Route::put('/admin/notifications/{event}', [NotificationSettingsController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.notifications.update');
            Route::delete('/admin/notifications/{event}', [NotificationSettingsController::class, 'destroy'])->middleware('security.track:admin.settings_changed')->name('admin.notifications.destroy');
            Route::post('/admin/notifications/{event}/test', [NotificationSettingsController::class, 'test'])
                ->middleware('throttle:provider-tests')
                ->name('admin.notifications.test');
            Route::get('/admin/navigation', [NavigationController::class, 'edit'])->name('admin.navigation.edit');
            Route::put('/admin/navigation', [NavigationController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.navigation.update');
            Route::put('/admin/navigation/site', [NavigationController::class, 'updateSite'])->middleware('security.track:admin.settings_changed')->name('admin.navigation.site.update');
            Route::post('/admin/navigation/links', [NavigationController::class, 'addLink'])->middleware('security.track:admin.settings_changed')->name('admin.navigation.links.store');
            Route::get('/admin/authentication', [AuthenticationSettingsController::class, 'edit'])->name('admin.authentication.edit');
            Route::put('/admin/authentication', [AuthenticationSettingsController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.authentication.update');
            Route::put('/admin/oauth-providers/{provider}', [OAuthProviderController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.oauth-providers.update');
            Route::get('/admin/api-docs', [ApiDocsSettingsController::class, 'edit'])->name('admin.api-docs.edit');
            Route::put('/admin/api-docs', [ApiDocsSettingsController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.api-docs.update');
        });

        // Aegis security centre. view_security: overview, guide and incident triage; manage_security: settings.
        Route::middleware('permission:view_security|manage_security')->group(function () {
            Route::get('/admin/security', [SecurityCenterController::class, 'index'])->name('admin.security.index');
            Route::get('/admin/security/guide', [SecurityGuideController::class, 'show'])->name('admin.security.guide');
            Route::get('/admin/security/incidents', [SecurityIncidentController::class, 'index'])->name('admin.security.incidents.index');
        });
        Route::middleware('permission:view_security')->group(function () {
            Route::get('/admin/security/incidents/{incident}', [SecurityIncidentController::class, 'show'])->name('admin.security.incidents.show');
            Route::put('/admin/security/incidents/{incident}', [SecurityIncidentController::class, 'update'])->name('admin.security.incidents.update');
            Route::post('/admin/security/incidents/{incident}/analyze', [SecurityIncidentController::class, 'analyze'])
                ->middleware('throttle:security-analysis')
                ->name('admin.security.incidents.analyze');
            Route::post('/admin/security/incidents/{incident}/restore-user', [SecurityIncidentController::class, 'restoreUser'])
                ->middleware(['permission:suspend_users', 'security.track:admin.access_changed'])
                ->name('admin.security.incidents.restore');
        });
        Route::middleware('permission:manage_security')->group(function () {
            Route::get('/admin/security/settings', [SecuritySettingsController::class, 'edit'])->name('admin.security.settings.edit');
            Route::put('/admin/security/settings', [SecuritySettingsController::class, 'update'])->name('admin.security.settings.update');
        });

        Route::middleware('permission:manage_integrations')->group(function () {
            Route::get('/admin/integrations', [IntegrationController::class, 'index'])->name('admin.integrations.index');
            Route::get('/admin/integrations/{provider}', [IntegrationController::class, 'show'])->name('admin.integrations.show');
            Route::put('/admin/integrations/{provider}', [IntegrationController::class, 'update'])->middleware('security.track:admin.integration_changed')->name('admin.integrations.update');
            Route::post('/admin/integrations/{provider}/test', [IntegrationController::class, 'test'])
                ->middleware('throttle:provider-tests')
                ->name('admin.integrations.test');
        });

        // Blog: the on/off switch, settings and posts.
        // Site assistant settings. The protected data list is fixed in code (AssistantDataPolicy).
        Route::middleware('permission:manage_settings')->group(function () {
            Route::get('/admin/assistant', [AssistantSettingsController::class, 'edit'])->name('admin.assistant.edit');
            Route::put('/admin/assistant', [AssistantSettingsController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.assistant.update');
        });

        // Languages: site translation settings and the translations themselves.
        Route::middleware('permission:manage_translations')->group(function () {
            Route::get('/admin/languages', [LanguageController::class, 'edit'])->name('admin.languages.edit');
            Route::put('/admin/languages', [LanguageController::class, 'update'])->middleware('security.track:admin.settings_changed')->name('admin.languages.update');
            Route::post('/admin/languages/translate', [LanguageController::class, 'translateNow'])->name('admin.languages.translate');
            Route::put('/admin/languages/strings/{string}', [LanguageController::class, 'saveTranslation'])->name('admin.languages.strings.update');
            Route::delete('/admin/languages/strings/{string}', [LanguageController::class, 'destroyString'])->name('admin.languages.strings.destroy');
        });

        // Campaign landing pages.
        Route::middleware('permission:manage_campaigns')->group(function () {
            Route::get('/admin/campaigns', [CampaignPageController::class, 'index'])->name('admin.campaigns.index');
            Route::post('/admin/campaigns', [CampaignPageController::class, 'store'])->name('admin.campaigns.store');
            Route::post('/admin/campaigns/images', [CampaignPageController::class, 'uploadImage'])->name('admin.campaigns.images.store');
            Route::get('/admin/campaigns/{campaign}/edit', [CampaignPageController::class, 'edit'])->name('admin.campaigns.edit');
            Route::put('/admin/campaigns/{campaign}', [CampaignPageController::class, 'update'])->name('admin.campaigns.update');
            Route::post('/admin/campaigns/{campaign}/duplicate', [CampaignPageController::class, 'duplicate'])->name('admin.campaigns.duplicate');
            Route::put('/admin/campaigns/{campaign}/home-page', [CampaignPageController::class, 'homePage'])
                ->middleware('security.track:admin.settings_changed')->name('admin.campaigns.home-page');
            Route::delete('/admin/campaigns/{campaign}', [CampaignPageController::class, 'destroy'])->name('admin.campaigns.destroy');
        });

        Route::middleware('permission:manage_blog')->group(function () {
            Route::get('/admin/blog', [BlogPostController::class, 'index'])->name('admin.blog.index');
            Route::put('/admin/blog/settings', [BlogPostController::class, 'updateSettings'])->middleware('security.track:admin.settings_changed')->name('admin.blog.settings.update');
            Route::post('/admin/blog/preview', [BlogPostController::class, 'preview'])->name('admin.blog.preview');
            Route::post('/admin/blog/images', [BlogPostController::class, 'uploadImage'])->name('admin.blog.images.store');
            Route::post('/admin/blog/categories', [BlogCategoryController::class, 'store'])->name('admin.blog.categories.store');
            Route::put('/admin/blog/categories/{category}', [BlogCategoryController::class, 'update'])->name('admin.blog.categories.update');
            Route::delete('/admin/blog/categories/{category}', [BlogCategoryController::class, 'destroy'])->name('admin.blog.categories.destroy');
            Route::get('/admin/blog/posts/create', [BlogPostController::class, 'create'])->name('admin.blog.create');
            Route::post('/admin/blog/posts', [BlogPostController::class, 'store'])->name('admin.blog.store');
            Route::get('/admin/blog/posts/{post}/edit', [BlogPostController::class, 'edit'])->name('admin.blog.edit');
            // POST rather than PUT so the cover image can be uploaded in the same request.
            Route::post('/admin/blog/posts/{post}', [BlogPostController::class, 'update'])->name('admin.blog.update');
            Route::delete('/admin/blog/posts/{post}', [BlogPostController::class, 'destroy'])->name('admin.blog.destroy');
        });

        // Site forms and the sign-up questions. They are edited in the shared builder below.
        Route::middleware('permission:manage_forms')->group(function () {
            Route::get('/admin/forms', [SiteFormController::class, 'index'])->name('admin.forms.index');
            Route::post('/admin/forms', [SiteFormController::class, 'store'])->name('admin.forms.store');
        });

        // Form builder for team and site forms: FormPolicy decides who may edit each form
        // (the team's owner and admins, or manage_forms for site forms).
        // The site assistant (Admin → Site assistant). Every message is screened by AssistantGuard.
        Route::middleware('registered')->group(function () {
            Route::get('/assistant/widget-claim', AssistantWidgetClaimController::class)
                ->middleware('throttle:assistant-widget-claim')->name('assistant.widget-claim');
            Route::get('/assistant', [AssistantController::class, 'show'])->name('assistant.show');
            Route::post('/assistant/messages', [AssistantController::class, 'send'])->middleware('throttle:assistant')->name('assistant.send');
            Route::delete('/assistant/messages', [AssistantController::class, 'clear'])->name('assistant.clear');
        });

        Route::middleware('registered')->group(function () {
            Route::get('/forms', [FormController::class, 'index'])->name('forms.index');
            Route::post('/forms', [FormController::class, 'store'])->name('forms.store');
            Route::post('/forms/{form}/duplicate', [FormController::class, 'duplicate'])->name('forms.duplicate');
            Route::get('/forms/{form}/edit', [FormController::class, 'edit'])->name('forms.edit');
            Route::put('/forms/{form}', [FormController::class, 'update'])->name('forms.update');
            Route::delete('/forms/{form}', [FormController::class, 'destroy'])->name('forms.destroy');
            Route::post('/forms/{form}/media', [FormMediaController::class, 'store'])->name('forms.media.store');
            Route::delete('/forms/{form}/media', [FormMediaController::class, 'destroy'])->name('forms.media.destroy');

            Route::get('/forms/{form}/responses', [FormSubmissionController::class, 'index'])->name('forms.submissions.index');
            Route::get('/forms/{form}/responses/export', [FormSubmissionController::class, 'export'])->name('forms.submissions.export');
            Route::delete('/forms/{form}/responses/{submission}', [FormSubmissionController::class, 'destroy'])
                ->scopeBindings()
                ->name('forms.submissions.destroy');
        });

        // Teams: authorization per team is handled by TeamPolicy.
        Route::middleware('registered')->group(function () {
            Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
            Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
            Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
            Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
            Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
            Route::put('/current-team', [CurrentTeamController::class, 'update'])->name('current-team.update');

            Route::post('/teams/{team}/invitations', [TeamInvitationController::class, 'store'])
                ->middleware('throttle:invitations')
                ->name('team-invitations.store');
            Route::delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])
                ->scopeBindings()
                ->name('team-invitations.destroy');

            Route::put('/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('team-members.update');
            Route::delete('/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('team-members.destroy');
            Route::put('/teams/{team}/owner', [TeamOwnershipController::class, 'update'])->name('team-owner.update');
        });
    });

    // Opened from the invitation email; guests are sent to sign in or register first and brought back here.
    Route::get('/team-invitations/{invitation}', [TeamInvitationController::class, 'accept'])
        ->middleware(['registered', 'signed'])
        ->name('team-invitations.accept');
});
