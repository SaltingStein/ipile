<?php

namespace App\Services\Integrations;

/**
 * The kind of service an integration provides. Each type is a section on the Integrations page.
 */
enum IntegrationType: string
{
    case Payment = 'payment';
    case Sms = 'sms';
    case Mail = 'mail';
    case Analytics = 'analytics';
    case Ai = 'ai';
    case Storage = 'storage';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment gateways',
            self::Sms => 'SMS',
            self::Mail => 'Email delivery',
            self::Analytics => 'Analytics',
            self::Ai => 'AI models',
            self::Storage => 'File storage & CDN',
            self::Assistant => 'Assistant widgets',
        };
    }
}
