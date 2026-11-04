<?php

namespace Tests;

use App\Http\Middleware\RedirectToSetup;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Test databases have no super admin, so the first-run redirect is off unless a test is about it.
     */
    protected bool $redirectsToSetup = false;

    protected function setUp(): void
    {
        parent::setUp();

        // The app talks to payment, email and SMS providers; no test may reach a real one.
        // Fake the exact endpoint a test needs with Http::fake().
        Http::preventStrayRequests();

        // Pages render without built assets, so the suite passes on a fresh clone and in CI.
        $this->withoutVite();

        if (! $this->redirectsToSetup) {
            $this->withoutMiddleware(RedirectToSetup::class);
        }
    }
}
