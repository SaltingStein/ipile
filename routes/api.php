<?php

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\OAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TeamMembershipController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\LegalPageController;
use Illuminate\Support\Facades\Route;

// Rate limits are named limiters defined in App\Services\RateLimits. Every /api/v1 group has a baseline
// (`api` when authenticated, `api-public` otherwise); sensitive routes add their own limiter on top.

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/webhooks/{provider}', WebhookController::class)->middleware('throttle:webhooks')->name('webhooks.payment');

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function () {
    Route::middleware('throttle:api-public')->group(function () {
        Route::post('/register', RegisterController::class)->middleware('throttle:register')->name('register');
        Route::post('/login', LoginController::class)->name('login');
        Route::post('/two-factor-challenge', TwoFactorChallengeController::class)->middleware('throttle:two-factor')->name('two-factor-challenge');

        Route::get('/oauth/providers', [OAuthController::class, 'providers'])->name('oauth.providers');
        Route::post('/oauth/{provider}', [OAuthController::class, 'token'])->middleware('throttle:oauth')->name('oauth.token');

        Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset')->name('forgot-password');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset')->name('reset-password');
    });

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::get('/user', [AccountController::class, 'show'])->name('user');
        Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
        Route::post('/email/verification-notification', [AccountController::class, 'sendVerificationEmail'])
            ->middleware('throttle:verification')
            ->name('verification.send');

        Route::get('/tokens', [TokenController::class, 'index'])->name('tokens.index');
        Route::delete('/tokens/{tokenId}', [TokenController::class, 'destroy'])->whereNumber('tokenId')->middleware('security.track:api.token_revoked')->name('tokens.destroy');
    });
});

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'active', 'verified', 'throttle:api'])->group(function () {
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/payments', [PaymentController::class, 'store'])->middleware(['throttle:payments', 'security.track:payment.initiated'])->name('payments.store');
    Route::get('/payments/{reference}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{reference}/verify', [PaymentController::class, 'verify'])->middleware('throttle:payment-verification')->name('payments.verify');

    // Accepting needs a verified email: the API has no signed link to prove the invitee owns the address.
    Route::post('/team-invitations/{invitation}/accept', [TeamMembershipController::class, 'acceptInvitation'])->name('team-invitations.accept');
});

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::apiResource('teams', TeamController::class);
    Route::put('/user/current-team', [TeamMembershipController::class, 'switchTeam'])->name('current-team.update');

    Route::post('/teams/{team}/invitations', [TeamMembershipController::class, 'invite'])
        ->middleware('throttle:invitations')
        ->name('team-invitations.store');
    Route::delete('/teams/{team}/invitations/{invitation}', [TeamMembershipController::class, 'cancelInvitation'])
        ->scopeBindings()
        ->name('team-invitations.destroy');

    Route::put('/teams/{team}/members/{user}', [TeamMembershipController::class, 'updateRole'])->name('team-members.update');
    Route::delete('/teams/{team}/members/{user}', [TeamMembershipController::class, 'removeMember'])->name('team-members.destroy');
    Route::put('/teams/{team}/owner', [TeamMembershipController::class, 'transferOwnership'])->name('team-owner.update');
});

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api-public')->group(function () {
    Route::get('/legal', [LegalPageController::class, 'indexApi'])->name('legal.index');
    Route::get('/legal/{document}', [LegalPageController::class, 'showApi'])->name('legal.show');
    Route::get('/consent', [ConsentController::class, 'config'])->name('consent.config');
    Route::post('/consent', [ConsentController::class, 'store'])->middleware('throttle:consent')->name('consent.store');
});
