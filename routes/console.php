<?php

use App\Events\Security\SecurityAccountRestored;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityRiskState;
use App\Models\User;
use App\Services\Auth\ApiTokens;
use App\Services\Blog\BlogAssets;
use App\Services\Campaigns\CampaignAssets;
use App\Services\Security\IncidentManager;
use App\Services\Security\Markov\BaselineTrainer;
use App\Services\Security\SecuritySettings;
use App\Services\Setup\SuperAdminSetup;
use App\Services\Translation\MachineTranslator;
use App\Services\Translation\StringCollector;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ipile:make-super-admin {email}', function (string $email, SuperAdminSetup $setup) {
    $user = User::query()->where('email', $email)->first();

    if ($user === null) {
        $this->error("No user found with email [{$email}]. Register the account first.");

        return 1;
    }

    $setup->promote($user);

    $this->info("{$email} is now a super admin.");

    return 0;
})->purpose('Grant the super_admin role to an existing user (bootstraps the first dashboard administrator)');

Artisan::command('ipile:restore-user {email}', function (string $email, IncidentManager $incidents) {
    $user = User::query()->where('email', $email)->first();

    if ($user === null) {
        $this->error("No user found with email [{$email}].");

        return 1;
    }

    $incident = SecurityIncident::query()->where('user_id', $user->getKey())
        ->where('status', '!=', SecurityIncident::STATUS_RESOLVED)
        ->latest('last_event_at')
        ->first();

    if (! $incidents->restoreAccount($user, null, $incident, SecurityAccountRestored::VIA_CONSOLE)) {
        $this->info("{$email} is not suspended.");

        return 0;
    }
    $this->info("{$email} can sign in again. Review and resolve their incident in Admin → Security → Incidents.");

    return 0;
})->purpose('Lift a suspension (e.g. by the site assistant guard rails) when no administrator can do it from the dashboard');

Artisan::command('ipile:setup-key', function (SuperAdminSetup $setup) {
    if (! $setup->isRequired()) {
        $this->error('Setup is already complete, so the /setup page is closed.');

        return 1;
    }

    $this->info('Open '.route('setup.create').' and enter this one-time setup key (it replaces any earlier key):');
    $this->line('  '.$setup->issueKey());

    return 0;
})->purpose('Issue a new one-time key for the first-run /setup page');

Artisan::command('security:train', function (BaselineTrainer $trainer) {
    $result = $trainer->train();

    $this->info("Trained {$result['scopes']} baselines from {$result['events']} events.");

    return 0;
})->purpose('Rebuild the Aegis Markov baselines from the security event log');

Artisan::command('security:prune', function (SecuritySettings $settings) {
    $cutoff = now()->subDays($settings->retentionDays());

    $events = SecurityEvent::query()->where('occurred_at', '<', $cutoff)->delete();
    $incidents = SecurityIncident::query()
        ->where('status', SecurityIncident::STATUS_RESOLVED)
        ->where('last_event_at', '<', $cutoff)
        ->delete();
    SecurityRiskState::query()->where('last_event_at', '<', $cutoff)->delete();

    $this->info("Deleted {$events} events and {$incidents} resolved incidents older than {$settings->retentionDays()} days.");

    return 0;
})->purpose('Delete Aegis security events and resolved incidents older than the retention period');

Artisan::command('api-tokens:prune-idle', function (ApiTokens $tokens) {
    $this->info("Deleted {$tokens->pruneIdle()} idle API tokens.");

    return 0;
})->purpose('Delete API tokens unused for longer than the idle timeout (Admin → Sign-in & 2FA)');

Artisan::command('blog:prune-images', function (BlogAssets $assets) {
    $this->info("Deleted {$assets->pruneOrphans()} unused blog pictures.");

    return 0;
})->purpose('Delete pictures uploaded in the blog editor more than a day ago that no post uses');

Artisan::command('campaigns:prune-images', function (CampaignAssets $assets) {
    $this->info("Deleted {$assets->pruneOrphans()} unused campaign pictures.");

    return 0;
})->purpose('Delete pictures uploaded in the campaign builder more than a day ago that no page uses');

Artisan::command('translations:translate {--max=500}', function (MachineTranslator $translator) {
    $this->info("Saved {$translator->translatePending((int) $this->option('max'))} translations.");

    return 0;
})->purpose('Translate public page text that has no translation yet, with the AI model chosen in Admin → Languages');

Artisan::command('translations:prune', function (StringCollector $collector) {
    $this->info("Deleted {$collector->pruneUnconfirmed()} texts that no second visitor reported.");

    return 0;
})->purpose('Delete reported page text that was never confirmed by a second visitor');

// Expired and idle tokens are already refused on every request; pruning just keeps the table small.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('02:00');
Schedule::command('api-tokens:prune-idle')->dailyAt('02:05');

// Runs whether or not Aegis is on, so retention is honoured after it is switched off.
Schedule::command('security:prune')->dailyAt('02:15');
Schedule::command('security:train')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('blog:prune-images')->dailyAt('02:45');
Schedule::command('campaigns:prune-images')->dailyAt('02:50');

// Does nothing while translation is off or has no AI model; the daily limit in Admin → Languages caps the cost.
Schedule::command('translations:translate')->everyTenMinutes()->withoutOverlapping();
Schedule::command('translations:prune')->dailyAt('03:00');
