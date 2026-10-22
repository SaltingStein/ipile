<?php

namespace App\Ai\Tools;

use App\Services\Assistant\AssistantKnowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Searches the internal guides ({@see AssistantKnowledge}) for the staff guidance mode only: how this site is built,
 * configured, run and extended.
 */
class SearchSiteGuide extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Search the site\'s internal guides: how this site is built on Ìpìlẹ̀, where each admin setting lives, who does what to run it, security monitoring, and how developers extend it. Returns up to 3 matching sections with the guide and section names.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('A few keywords, e.g. "add an integration" or "security signal".')->required(),
        ];
    }

    protected function read(Request $request): array|string
    {
        $sections = app(AssistantKnowledge::class)->search((string) $request['query']);

        return $sections === [] ? 'No guide section matches. Say so, and suggest the Help Center or the developer guide.' : $sections;
    }
}
