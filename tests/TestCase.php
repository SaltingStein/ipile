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

    /**
     * Move past the soft-delete retention window and run the scheduled purge, so a
     * test can check what happens to a record (and its files) once it is gone for good.
     */
    protected function purgeDeletedRecords(): void
    {
        $this->travel((int) config('retention.deleted_records_days') + 1)->days();
        $this->artisan('model:prune')->assertSuccessful();
    }
}
