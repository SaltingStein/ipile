<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogPostRequest;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\Blog\BlogAssets;
use App\Services\Blog\BlogSettings;
use App\Services\Media\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Blog: the blog's on/off switch and its posts. Routes require `manage_blog`.
 */
class BlogPostController extends Controller
{
    public function index(BlogSettings $settings): Response
    {
        $posts = BlogPost::query()->with(['author', 'categories'])->latest('updated_at')->paginate(20);

        return Inertia::render('Admin/Blog/Index', [
            'settings' => $settings->all(),
            'categories' => BlogCategory::query()->withCount('posts')->orderBy('name')->get()
                ->map(fn (BlogCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'posts_count' => $category->posts_count,
                ]),
            'maxCategories' => BlogCategory::MAX_CATEGORIES,
            'posts' => BlogPostResource::collection($posts),
            'publicUrl' => route('blog.index'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Blog/Editor', [
            'post' => null,
            'categories' => $this->categoryOptions(),
            'coverLimitKb' => $this->coverLimitKb(),
        ]);
    }

    public function store(BlogPostRequest $request): RedirectResponse
    {
        $post = new BlogPost($request->postAttributes());
        $post->author()->associate($request->user());
        $post->save();
        $post->categories()->sync($request->categoryIds());
        $this->saveCover($post, $request);

        return redirect()->route('admin.blog.edit', $post)->with('status', $this->savedMessage($post));
    }

    public function edit(Request $request, BlogPost $post): Response
    {
        $post->load('categories');

        return Inertia::render('Admin/Blog/Editor', [
            'post' => (new BlogPostResource($post))->forEditor()->resolve($request),
            'categories' => $this->categoryOptions(),
            'coverLimitKb' => $this->coverLimitKb(),
        ]);
    }

    public function update(BlogPostRequest $request, BlogPost $post, BlogAssets $assets): RedirectResponse
    {
        $oldBody = $post->body;
        $post->update($request->postAttributes());
        $assets->pruneRemovedImages($post, $oldBody);
        $post->categories()->sync($request->categoryIds());
        $this->saveCover($post, $request);

        return back()->with('status', $this->savedMessage($post));
    }

    /**
     * Delete the post with its cover and the body pictures no other post uses.
     */
    public function destroy(BlogPost $post, BlogAssets $assets): RedirectResponse
    {
        $post->delete();
        $assets->deleteFor($post);

        return redirect()->route('admin.blog.index')->with('status', __('Post deleted.'));
    }

    /**
     * Render Markdown for the editor's Preview tab, exactly as the public page will.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate(['body' => ['present', 'nullable', 'string', 'max:200000']]);

        return response()->json(['html' => (new BlogPost(['body' => $validated['body'] ?? '']))->html()]);
    }

    /**
     * Upload a picture for the post body (the editor's image button, paste or drag and drop) and return its address.
     */
    public function uploadImage(Request $request, MediaStorage $storage): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.$this->coverLimitKb(), 'dimensions:max_width=6000,max_height=6000'],
        ], [
            'image.uploaded' => 'The image is larger than this server accepts. Try a smaller file.',
        ]);

        $reference = $storage->store($request->file('image'), BlogAssets::CONTENT_DIRECTORY, 'image');

        return response()->json(['url' => $storage->url($reference)], 201);
    }

    /**
     * Turn the blog on or off and set its title and introduction.
     */
    public function updateSettings(Request $request, BlogSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'title' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $settings->update((bool) $validated['enabled'], $validated['title'], $validated['description'] ?? null);

        return back()->with('status', $validated['enabled'] ? __('Blog settings saved. The blog is on.') : __('Blog settings saved. The blog is off.'));
    }

    /**
     * Replace or remove the cover image. The old file is deleted only after the new one is stored.
     */
    private function saveCover(BlogPost $post, BlogPostRequest $request): void
    {
        $storage = app(MediaStorage::class);
        $oldPath = $post->cover_image_path;

        if ($request->hasFile('cover')) {
            $post->forceFill(['cover_image_path' => $storage->store($request->file('cover'), BlogAssets::COVER_DIRECTORY, 'cover')])->save();
        } elseif ($request->boolean('remove_cover')) {
            $post->forceFill(['cover_image_path' => null])->save();
        } else {
            return;
        }

        if ($oldPath !== null) {
            $storage->delete($oldPath);
        }
    }

    private function savedMessage(BlogPost $post): string
    {
        return match (true) {
            $post->isScheduled() => __('Saved. The post will be published on :date.', ['date' => $post->published_at->toDayDateTimeString()]),
            $post->isVisible() => __('Saved. The post is published.'),
            default => __('Draft saved.'),
        };
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        return BlogCategory::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (BlogCategory $category) => ['id' => $category->id, 'name' => $category->name])->all();
    }

    private function coverLimitKb(): int
    {
        return min(BlogPostRequest::COVER_MAX_KB, (int) floor(UploadedFile::getMaxFilesize() / 1024));
    }
}
