<?php

namespace App\Services\Assistant;

use App\Models\SecurityIncident;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\DB;

/**
 * Publishes only bounded status counts. Assistant tools never read the underlying SMS or incident logs.
 */
class InsightSummaries
{
    public function __construct(private AssistantSettings $settings) {}

    public function refresh(): int
    {
        $selected = $this->settings->insights();

        if (! $this->settings->enabled() || ! $this->settings->staffModeEnabled(AssistantIntent::Analytics)) {
            return 0;
        }

        $count = 0;

        foreach ([
            'sms.delivery' => [SmsMessage::class, [SmsMessage::STATUS_QUEUED, SmsMessage::STATUS_SENT, SmsMessage::STATUS_FAILED, SmsMessage::STATUS_DELIVERED, SmsMessage::STATUS_UNDELIVERED]],
            'security.incidents' => [SecurityIncident::class, [SecurityIncident::STATUS_OPEN, SecurityIncident::STATUS_ACKNOWLEDGED, SecurityIncident::STATUS_RESOLVED]],
        ] as $key => [$model, $statuses]) {
            if (! in_array($key, $selected, true)) {
                continue;
            }

            $values = $model::query()->whereBetween('created_at', [now()->subDays(30), now()])
                ->whereIn('status', $statuses)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->orderBy('status')
                ->get()->map(fn ($row): array => ['status' => $row->status, 'total' => (int) $row->getAttribute('total')])->all();

            DB::table('assistant_insight_summaries')->updateOrInsert(['key' => $key], [
                'values' => json_encode($values), 'refreshed_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }
}
