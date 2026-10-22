<?php

namespace App\Http\Controllers;

use App\Services\Help\HelpArticle;
use App\Services\Help\HelpCenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app help for end users. Articles are Markdown files in resources/help; users only see the ones they have permission for.
 */
class HelpCenterController extends Controller
{
    public function index(Request $request, HelpCenter $help): Response
    {
        return Inertia::render('Help/Index', [
            'articles' => $help->visibleTo($request->user())->map(fn (HelpArticle $article) => $article->summaryDetails())->all(),
        ]);
    }

    public function show(Request $request, HelpCenter $help, string $slug): Response
    {
        $article = $help->find($slug, $request->user());
        abort_if($article === null, 404);

        return Inertia::render('Help/Show', [
            'article' => [...$article->summaryDetails(), 'html' => $article->html()],
        ]);
    }
}
