<?php

namespace App\Services\ApiDocs;

/**
 * Who may open the generated API documentation at /docs/api.
 */
enum ApiDocsVisibility: string
{
    case Public = 'public';
    case Authenticated = 'authenticated';
    case Permission = 'permission';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Authenticated => 'Signed-in users',
            self::Permission => 'API docs permission only',
            self::Disabled => 'Disabled',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Anyone with the link, including search engines and signed-out visitors.',
            self::Authenticated => 'Any registered, signed-in user. Guest accounts are asked to sign in.',
            self::Permission => 'Only users with the manage_api_docs permission (and super admins).',
            self::Disabled => 'The docs pages return "Not found" for everyone.',
        };
    }
}
