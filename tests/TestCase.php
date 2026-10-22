<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The app talks to payment, email and SMS providers; no test may reach a real one.
        // Fake the exact endpoint a test needs with Http::fake().
        Http::preventStrayRequests();

        // Pages render without built assets, so the suite passes on a fresh clone and in CI.
        $this->withoutVite();
    }
}
