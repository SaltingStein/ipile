<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Blog → Categories: add, rename and remove blog categories. Routes require `manage_blog`.
 * Removing a category leaves its posts in place; they are only taken out of the category.
 */
class BlogCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        if (BlogCategory::query()->count() >= BlogCategory::MAX_CATEGORIES) {
            throw ValidationException::withMessages(['name' => __('You can have up to :max categories. Remove one first.', ['max' => BlogCategory::MAX_CATEGORIES])]);
        }

        $validated = $this->validated($request);

        BlogCategory::query()->create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('status', __('Category “:name” added.', ['name' => $validated['name']]));
    }

    public function update(Request $request, BlogCategory $category): RedirectResponse
    {
        $validated = $this->validated($request, $category);

        $category->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('status', __('Category saved.'));
    }

    public function destroy(BlogCategory $category): RedirectResponse
    {
        $category->delete();

        return back()->with('status', __('Category “:name” removed. Its posts are still on the blog.', ['name' => $category->name]));
    }

    /**
     * @return array{name: string, description?: string|null}
     */
    private function validated(Request $request, ?BlogCategory $category = null): array
    {
        /** @var array{name: string, description?: string|null} */
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique(BlogCategory::class, 'name')->ignore($category)],
            'description' => ['nullable', 'string', 'max:300'],
        ], [
            'name.unique' => 'There is already a category with this name.',
        ]);
    }

    /**
     * "Events & news" → "events-news", with a number added when the address is taken. The address stays
     * the same when a category is renamed, so shared links keep working.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 70, '') ?: 'category';
        $slug = $base;

        for ($number = 2; BlogCategory::query()->where('slug', $slug)->exists(); $number++) {
            $slug = "{$base}-{$number}";
        }

        return $slug;
    }
}
