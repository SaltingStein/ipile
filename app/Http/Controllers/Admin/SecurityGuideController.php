<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\BrowserSessionRegistry;
use App\Services\Security\Analysis\SecurityAnalyst;
use App\Services\Security\SecurityAction;
use App\Services\Security\SecurityEventCatalog;
use App\Services\Security\SecurityMode;
use App\Services\Security\SecuritySettings;
use App\Services\Security\Signals\SecuritySignal;
use App\Services\Security\Signals\SecuritySignals;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Security → Guide: what Aegis needs to run on this server (a live checklist), what it covers (scope,
 * read from the event catalog) and how to extend it. Read from code and settings so it cannot drift.
 */
class SecurityGuideController extends Controller
{
    public function show(
        SecuritySettings $settings,
        SecurityEventCatalog $catalog,
        SecuritySignals $signals,
        SecurityAnalyst $analyst,
        BrowserSessionRegistry $sessions,
    ): Response {
        $mode = $settings->mode();
        $lastTrainedAt = $settings->lastTrainedAt();
        $isTrainingCurrent = $lastTrainedAt !== null && Carbon::parse($lastTrainedAt)->greaterThan(now()->subDays(2));
        $thresholds = $settings->thresholds();

        return Inertia::render('Admin/SecurityGuide', [
            'checklist' => [
                [
                    'label' => 'Aegis is switched on',
                    'ok' => $mode !== SecurityMode::Off,
                    'detail' => "Current mode: {$mode->label()}. Start in “Monitor only”, review incidents for a week or two, then switch to “Monitor and respond”.",
                ],
                [
                    'label' => 'A queue worker runs jobs in the background',
                    'ok' => config('queue.default') !== 'sync',
                    'detail' => 'AI analysis and security alerts are queued. With the “sync” queue they run inside the user\'s request and slow it down. Run `php artisan queue:work` under a process supervisor.',
                ],
                [
                    'label' => 'Baselines are trained',
                    'ok' => $isTrainingCurrent,
                    'detail' => $lastTrainedAt === null
                        ? 'Never trained, so the Markov sequence signal scores nothing yet. Run `php artisan security:train`, and run `php artisan schedule:run` every minute (cron) so it retrains daily.'
                        : 'Last trained '.Carbon::parse($lastTrainedAt)->diffForHumans().'. Training runs daily at 02:30 when the scheduler is running.',
                ],
                [
                    'label' => 'Browser sessions can be ended remotely',
                    'ok' => $sessions->isSupported(),
                    'detail' => '“Sign out everywhere” deletes stored sessions, which needs SESSION_DRIVER=database. With other drivers, API tokens, trusted devices and remember-me cookies are still revoked, but other browsers stay signed in until their session expires.',
                ],
                [
                    'label' => 'AI analyst (optional)',
                    'ok' => $settings->aiEnabled() && $analyst->isAvailable() ? true : null,
                    'detail' => $analyst->isAvailable()
                        ? ($settings->aiEnabled() ? 'Incidents scoring '.$settings->aiThreshold().' or more are sent for analysis.' : 'Its AI model is ready; turn on AI analysis in Monitoring settings.')
                        : 'Switch on an AI model under Admin → Integrations → AI models, choose it for the analyst in Monitoring settings, then turn on AI analysis.',
                ],
            ],
            'modes' => array_map(fn (SecurityMode $option) => ['label' => $option->label(), 'description' => $option->description()], SecurityMode::cases()),
            'actions' => array_map(fn (SecurityAction $action) => [
                'label' => $action->label(),
                'description' => $action->description(),
                'threshold' => $thresholds[$action->value] ?? null,
            ], array_reverse(SecurityAction::ladder())),
            'incidentThreshold' => $settings->incidentThreshold(),
            'retentionDays' => $settings->retentionDays(),
            'categories' => array_map(fn (array $category) => [
                ...$category,
                'monitored' => $settings->isCategoryMonitored($category['key']),
            ], $catalog->categories()),
            'signals' => array_map(fn (SecuritySignal $signal) => [
                'key' => $signal->key(),
                'label' => $signal->label(),
                'description' => $signal->description(),
                'weight' => $signal->weight(),
            ], $signals->all()),
            'urls' => [
                'index' => route('admin.security.index'),
                'settings' => route('admin.security.settings.edit'),
            ],
        ]);
    }
}
