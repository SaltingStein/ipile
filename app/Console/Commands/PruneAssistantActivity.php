<?php

namespace App\Console\Commands;

use App\Models\AiInteraction;
use App\Models\AssistantConversation;
use App\Services\Assistant\AssistantSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deletes site assistant chats and interaction log entries older than the period set in Admin → Site assistant.
 */
#[Signature('assistant:prune-activity')]
#[Description('Delete assistant chats and activity log entries older than the retention period (Admin → Site assistant)')]
class PruneAssistantActivity extends Command
{
    public function handle(AssistantSettings $settings): int
    {
        $cutoff = now()->subDays($settings->activityRetentionDays());

        $interactions = AiInteraction::query()->where('created_at', '<', $cutoff)->delete();
        $conversations = AssistantConversation::query()->where('updated_at', '<', $cutoff)->delete();

        $this->info("Deleted {$interactions} activity entries and {$conversations} chats older than {$settings->activityRetentionDays()} days.");

        return self::SUCCESS;
    }
}
