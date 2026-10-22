<?php

namespace App\Ai\Tools;

use App\Services\Help\HelpArticle;
use App\Services\Help\HelpCenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Finds Help Center articles this person can see, for "how do I…?" questions.
 */
class SearchHelpArticles extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Search the site\'s Help Center for how-to articles. Use it for any question about how to do something on this site. Returns up to 3 articles with their address and text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('A few keywords, e.g. "reset password" or "invite team member".')->required(),
        ];
    }

    protected function read(Request $request): array|string
    {
        $words = array_filter(preg_split('/\W+/u', Str::lower((string) $request['query'])) ?: [], fn (string $word) => mb_strlen($word) > 2);

        if ($words === []) {
            return 'Give a few keywords to search for.';
        }

        $matches = app(HelpCenter::class)->visibleTo($this->user)
            ->map(function (HelpArticle $article) use ($words) {
                $title = Str::lower($article->title.' '.$article->summary);
                $body = Str::lower($article->markdown);
                $score = array_sum(array_map(fn (string $word) => substr_count($title, $word) * 3 + substr_count($body, $word), $words));

                return ['article' => $article, 'score' => $score];
            })
            ->filter(fn (array $match) => $match['score'] > 0)
            ->sortByDesc('score')
            ->take(3)
            ->values();

        if ($matches->isEmpty()) {
            return 'No help article matches. Say so and suggest browsing the Help Center at '.url('/help').'.';
        }

        return $matches->map(fn (array $match) => [
            'title' => $match['article']->title,
            'url' => url('/help/'.$match['article']->slug),
            'text' => Str::limit($match['article']->markdown, 2500),
        ])->all();
    }
}
