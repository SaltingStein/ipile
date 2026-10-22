<?php

namespace Database\Factories;

use App\Models\BlogPost;
use App\Models\User;
use App\Services\Blog\BlogPostStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A published post with a Markdown body, published a day ago.
 *
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->words(4, true));

        return [
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt' => null,
            'body' => "## Introduction\n\n".fake()->paragraph()."\n\n".fake()->paragraph(),
            'status' => BlogPostStatus::Published,
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => BlogPostStatus::Draft, 'published_at' => null]);
    }

    public function scheduled(): static
    {
        return $this->state(['status' => BlogPostStatus::Published, 'published_at' => now()->addWeek()]);
    }
}
