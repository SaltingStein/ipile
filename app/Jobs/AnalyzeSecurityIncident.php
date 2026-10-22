<?php

namespace App\Jobs;

use App\Models\SecurityIncident;
use App\Services\Security\Analysis\SecurityAnalysisException;
use App\Services\Security\Analysis\SecurityAnalyst;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the AI analyst to review an incident and stores its opinion on the incident. Never changes the account.
 */
class AnalyzeSecurityIncident implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $incidentId) {}

    public function handle(SecurityAnalyst $analyst): void
    {
        $incident = SecurityIncident::query()->find($this->incidentId);

        if ($incident === null) {
            return;
        }

        try {
            $analysis = $analyst->analyze($incident);
        } catch (SecurityAnalysisException $exception) {
            $this->markFailed($incident, $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            Log::warning('Security incident analysis failed.', ['incident' => $incident->getKey(), 'exception' => $exception]);
            $this->markFailed($incident, __('The analysis failed. Check the logs and try again.'));

            return;
        }

        $incident->forceFill([
            'ai_status' => SecurityIncident::AI_COMPLETED,
            'ai_analysis' => $analysis->toArray(),
            'ai_analyzed_at' => now(),
        ])->save();
    }

    private function markFailed(SecurityIncident $incident, string $message): void
    {
        $incident->forceFill([
            'ai_status' => SecurityIncident::AI_FAILED,
            'ai_analysis' => ['error' => $message],
            'ai_analyzed_at' => now(),
        ])->save();
    }
}
