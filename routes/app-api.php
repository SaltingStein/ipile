<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Your app's API routes
|--------------------------------------------------------------------------
|
| Put your product's API endpoints here; Ìpìlẹ̀'s own API lives in routes/api.php. Everything in this
| file is under /api. Routes under /api/v1 show up in the generated API docs (/docs/api) with no extra
| step, and a test fails if one cannot be documented. Mobile apps and scripts call them with a Sanctum
| token (Authorization: Bearer …).
|
*/

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    // Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
});
