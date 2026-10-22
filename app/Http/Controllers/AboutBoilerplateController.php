<?php

namespace App\Http\Controllers;

use App\Services\BrandingSettings;
use Inertia\Inertia;
use Inertia\Response;

class AboutBoilerplateController extends Controller
{
    public function show(BrandingSettings $branding): Response
    {
        abort_unless($branding->aboutPageVisible(), 404);

        return Inertia::render('AboutBoilerplate');
    }
}
