<?php

namespace App\Services\Security\Analysis;

use App\Models\SecurityIncident;

/**
 * Reviews an incident and explains it for an administrator. Advisory only: nothing it says is acted on
 * automatically. Bind your own implementation in a service provider to use another model or an in-house tool.
 */
interface SecurityAnalyst
{
    /**
     * Whether the analyst is configured (e.g. its integration is enabled).
     */
    public function isAvailable(): bool;

    /**
     * @throws SecurityAnalysisException when the analysis could not be produced.
     */
    public function analyze(SecurityIncident $incident): SecurityAnalysis;
}
