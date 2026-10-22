<?php

namespace App\Services\ApiDocs;

use App\Models\Setting;
use App\Models\User;

/**
 * Dashboard-managed access to the generated API documentation (Scramble, /docs/api and /docs/api.json).
 */
class ApiDocsSettings
{
    public const VISIBILITY = 'api_docs.visibility';

    public const DEFAULT_VISIBILITY = ApiDocsVisibility::Permission;

    public function visibility(): ApiDocsVisibility
    {
        return ApiDocsVisibility::tryFrom((string) Setting::valueOf(self::VISIBILITY)) ?? self::DEFAULT_VISIBILITY;
    }

    /**
     * Whether this person may open the API docs and developer portal (the same rules as EnsureApiDocsAreVisible).
     */
    public function isVisibleTo(?User $user): bool
    {
        return match ($this->visibility()) {
            ApiDocsVisibility::Disabled => false,
            ApiDocsVisibility::Public => true,
            ApiDocsVisibility::Authenticated => $user !== null && ! $user->is_guest,
            ApiDocsVisibility::Permission => $user !== null && ! $user->is_guest && $user->can('manage_api_docs'),
        };
    }

    public function update(ApiDocsVisibility $visibility): void
    {
        Setting::store(self::VISIBILITY, $visibility->value);
    }
}
