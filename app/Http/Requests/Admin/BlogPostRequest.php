<?php

namespace App\Http\Requests\Admin;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\Blog\BlogPostStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A blog post from the Admin → Blog editor. Access is checked by the route's `permission:manage_blog`.
 */
class BlogPostRequest extends FormRequest
{
    public const COVER_MAX_KB = 5 * 1024;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $post = $this->route('post');

        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // /blog/feed is the RSS feed.
                Rule::notIn(['feed']),
                Rule::unique(BlogPost::class, 'slug')->ignore($post),
            ],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:200000'],
            'status' => ['required', Rule::enum(BlogPostStatus::class)],
            'published_at' => ['nullable', 'date'],
            'category_ids' => ['sometimes', 'array', 'max:'.BlogCategory::MAX_CATEGORIES],
            'category_ids.*' => ['integer', 'distinct', Rule::exists(BlogCategory::class, 'id')],
            'remove_cover' => ['sometimes', 'boolean'],
            'cover' => [
                'nullable', Rule::prohibitedIf($this->boolean('remove_cover')),
                'image', 'mimes:jpg,jpeg,png,webp', 'max:'.min(self::COVER_MAX_KB, (int) floor(UploadedFile::getMaxFilesize() / 1024)),
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes, for example our-first-event.',
            'slug.unique' => 'Another post already uses this address.',
            'slug.not_in' => 'This address is reserved. Choose another.',
            'cover.uploaded' => 'The image is larger than this server accepts. Try a smaller file.',
        ];
    }

    /**
     * The chosen categories. Browsers leave out an empty list, so a missing list means "no categories".
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return array_map('intval', $this->validated('category_ids', []));
    }

    /**
     * The post's text fields. A published post with no time is published now.
     *
     * @return array{title: string, slug: string, excerpt: string|null, body: string, status: string, published_at: mixed}
     */
    public function postAttributes(): array
    {
        $publishedAt = $this->validated('published_at');

        if ($this->validated('status') === BlogPostStatus::Published->value && $publishedAt === null) {
            $publishedAt = now();
        }

        return [
            'title' => $this->validated('title'),
            'slug' => $this->validated('slug'),
            'excerpt' => $this->validated('excerpt'),
            'body' => $this->validated('body'),
            'status' => $this->validated('status'),
            'published_at' => $publishedAt,
        ];
    }
}
