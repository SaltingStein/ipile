<?php

namespace App\Services\Assistant;

/**
 * What a message to the site assistant is about, and so which mode answers it ({@see AssistantIntentRouter}).
 *
 * - {@see EndUserSupport}: the product-facing assistant everyone with a full account gets (how-to questions, their
 *   own account and teams, allowed figures). The default and the fallback.
 * - {@see CoreGuidance}: staff guidance on how this site is built, run and extended, answered from the internal
 *   guides ({@see AssistantKnowledge}) as well as the Help Center.
 * - {@see Analytics}: staff analysis of the business figures administrators allowed.
 *
 * The two staff modes are internal: each needs its own permission and an administrator switching it on.
 */
enum AssistantIntent: string
{
    case CoreGuidance = 'core_guidance';
    case Analytics = 'analytics';
    case EndUserSupport = 'end_user_support';

    /**
     * @return list<self>
     */
    public static function internal(): array
    {
        return [self::CoreGuidance, self::Analytics];
    }

    public function isInternal(): bool
    {
        return $this !== self::EndUserSupport;
    }

    /**
     * The permission a person needs for this mode; null for the product-facing assistant.
     */
    public function permission(): ?string
    {
        return match ($this) {
            self::CoreGuidance => 'use_assistant_guidance',
            self::Analytics => 'use_assistant_analytics',
            self::EndUserSupport => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CoreGuidance => 'Site guidance',
            self::Analytics => 'Analytics',
            self::EndUserSupport => 'Help',
        };
    }
}
