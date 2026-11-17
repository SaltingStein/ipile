<?php

use App\Http\Controllers\Admin\MarketingSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MarketingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Your app's routes
|--------------------------------------------------------------------------
|
| Put your product's pages here; Ìpìlẹ̀'s own routes live in routes/web.php, so keeping yours
| in this file makes boilerplate updates painless. Everything here gets the "web" middleware.
| `php artisan ipile:page Reports` adds a page, its route and a test for you.
| List a page in the workspace sidebar from resources/js/appNavigation.ts.
|
*/

// Signed-in pages (guest-session users included). Add 'registered' to require a full account,
// or 'permission:your_permission' to limit a page to users with that permission.
Route::middleware(['auth', 'auth.session', 'two-factor.configured'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // ipile:page adds signed-in pages above this line.
});

// Site-wide marketing controls.
Route::middleware(['auth', 'auth.session', 'two-factor.configured', 'permission:manage_settings'])->group(function () {
    Route::get('/admin/marketing', [MarketingSettingsController::class, 'edit'])->name('admin.marketing.edit');
    Route::put('/admin/marketing', [MarketingSettingsController::class, 'update'])
        ->middleware('security.track:admin.settings_changed')->name('admin.marketing.update');
});

// Public pages anyone can open, signed in or not.
Route::get('/marketing', MarketingController::class)->name('marketing');
// ipile:page adds public pages above this line.
