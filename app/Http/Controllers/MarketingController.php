<?php

namespace App\Http\Controllers;

use App\Services\MarketingSettings;
use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function __invoke(MarketingSettings $settings): Response
    {
        abort_unless($settings->enabled(), 404);

        return Inertia::render('Marketing', [
            'repositoryUrl' => $settings->repositoryUrl(),
        ]);
    }
}
