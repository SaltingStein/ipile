<?php

namespace App\Http\Controllers;

use App\Http\Resources\BlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\Blog\BlogSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public blog at /blog (and each category at /blog/category/{slug}), switched on and off in Admin → Blog. Blog managers can open any post (drafts,
 * scheduled posts, or while the blog is off) to preview it.
 */
class BlogController extends Controller
{
    public const PER_PAGE = 9;

    public function index(Request $request, BlogSettings $settings): Response
    {
        abort_unless($settings->enabled(), 404);

        $posts = BlogPost::query()->visible()->with(['author', 'categories'])->latest('published_at')->latest('id')->paginate(self::PER_PAGE);

        return Inertia::render('Blog/Index', [
            'blog' => ['title' => $settings->title(), 'description' => $settings->description()],
            'posts' => BlogPostResource::collection($posts),
            'categories' => $this->categoriesWithPosts(),
            'category' => null,
        ]);
    }

    public function category(BlogCategory $category, BlogSettings $settings): Response
    {
        abort_unless($settings->enabled(), 404);

        $posts = $category->posts()->visible()->with(['author', 'categories'])->latest('published_at')->latest('id')->paginate(self::PER_PAGE);

        return Inertia::render('Blog/Index', [
            'blog' => ['title' => $settings->title(), 'description' => $settings->description()],
            'posts' => BlogPostResource::collection($posts),
            'categories' => $this->categoriesWithPosts(),
            'category' => ['name' => $category->name, 'slug' => $category->slug, 'description' => $category->description],
        ]);
    }

    public function show(Request $request, BlogPost $post, BlogSettings $settings): Response
    {
        $isPreview = $request->user()?->can('manage_blog') === true && (! $settings->enabled() || ! $post->isVisible());
        abort_unless(($settings->enabled() && $post->isVisible()) || $isPreview, 404);

        $post->load(['author', 'categories']);

        return Inertia::render('Blog/Show', [
            'blog' => ['title' => $settings->title()],
            'post' => (new BlogPostResource($post))->withBody()->resolve($request),
            'isPreview' => $isPreview,
            'editUrl' => $request->user()?->can('manage_blog') ? route('admin.blog.edit', $post) : null,
        ]);
    }

    /**
     * RSS 2.0 feed of the 20 newest posts, for feed readers and newsletter tools.
     */
    public function feed(BlogSettings $settings): HttpResponse
    {
        abort_unless($settings->enabled(), 404);

        $posts = BlogPost::query()->visible()->with('categories')->latest('published_at')->limit(20)->get();
        $escape = fn (?string $value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $items = $posts->map(fn (BlogPost $post) => '<item>'
            .'<title>'.$escape($post->title).'</title>'
            .'<link>'.$escape(route('blog.show', $post)).'</link>'
            .'<guid isPermaLink="true">'.$escape(route('blog.show', $post)).'</guid>'
            .'<pubDate>'.$post->published_at->toRssString().'</pubDate>'
            .$post->categories->map(fn (BlogCategory $category) => '<category>'.$escape($category->name).'</category>')->implode('')
            .'<description>'.$escape($post->summary()).'</description>'
            .'</item>')->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel>'
            .'<title>'.$escape($settings->title()).'</title>'
            .'<link>'.$escape(route('blog.index')).'</link>'
            .'<description>'.$escape($settings->description() ?? $settings->title()).'</description>'
            .$items
            .'</channel></rss>';

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /**
     * Categories that have at least one post visitors can read, for the category links above the posts.
     *
     * @return list<array{name: string, slug: string}>
     */
    private function categoriesWithPosts(): array
    {
        return BlogCategory::query()
            ->whereHas('posts', fn ($query) => $query->visible())
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn (BlogCategory $category) => ['name' => $category->name, 'slug' => $category->slug])
            ->all();
    }
}
