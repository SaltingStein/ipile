<?php

namespace Tests\Feature\Blog;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Services\Blog\BlogSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers BlogController: the public blog, categories, video embeds, its on/off switch, previews and the RSS feed.
 */
class BlogTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_is_not_found_while_switched_off(): void
    {
        $post = BlogPost::factory()->create();

        $this->get(route('blog.index'))->assertNotFound();
        $this->get(route('blog.show', $post))->assertNotFound();
        $this->get(route('blog.feed'))->assertNotFound();
    }

    public function test_index_lists_only_published_posts_whose_time_has_come_newest_first(): void
    {
        $this->switchOn();
        $older = BlogPost::factory()->create(['published_at' => now()->subWeek()]);
        $newer = BlogPost::factory()->create(['published_at' => now()->subHour()]);
        BlogPost::factory()->draft()->create();
        BlogPost::factory()->scheduled()->create();

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Index')
                ->where('blog.title', 'News')
                ->has('posts.data', 2)
                ->where('posts.data.0.id', $newer->id)
                ->where('posts.data.1.id', $older->id)
                ->missing('posts.data.0.body'));
    }

    public function test_post_page_renders_markdown_without_scripts_or_unsafe_links(): void
    {
        $this->switchOn();
        $post = BlogPost::factory()->create([
            'body' => "## Agenda\n\n**Doors open** at 9.\n\n<script>alert('x')</script>\n\n[Click](javascript:alert(1))",
        ]);

        $this->get(route('blog.show', $post))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Show')
                ->where('isPreview', false)
                ->where('editUrl', null)
                ->where('post.html', fn (string $html) => str_contains($html, '<h2>Agenda</h2>')
                    && str_contains($html, '<strong>Doors open</strong>')
                    && ! str_contains($html, '<script')
                    && ! str_contains($html, 'javascript:')));
    }

    public function test_drafts_and_scheduled_posts_are_hidden_from_visitors(): void
    {
        $this->switchOn();

        $this->get(route('blog.show', BlogPost::factory()->draft()->create()))->assertNotFound();
        $this->get(route('blog.show', BlogPost::factory()->scheduled()->create()))->assertNotFound();
    }

    public function test_blog_managers_can_preview_drafts_even_while_the_blog_is_off(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_blog');
        $draft = BlogPost::factory()->draft()->create();

        $this->actingAs($manager)->get(route('blog.show', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('isPreview', true)->where('editUrl', route('admin.blog.edit', $draft)));
        $this->actingAs(User::factory()->create())->get(route('blog.show', $draft))->assertNotFound();
    }

    public function test_feed_lists_visible_posts_with_escaped_titles(): void
    {
        $this->switchOn();
        $post = BlogPost::factory()->create(['title' => 'Tips & tricks <2026>']);
        BlogPost::factory()->draft()->create(['title' => 'Secret draft']);

        $response = $this->get(route('blog.feed'));

        $response->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertSame('News', (string) $xml->channel->title);
        $this->assertCount(1, $xml->channel->item);
        $this->assertSame('Tips & tricks <2026>', (string) $xml->channel->item[0]->title);
        $this->assertSame(route('blog.show', $post), (string) $xml->channel->item[0]->link);
    }

    public function test_category_page_lists_only_its_visible_posts_and_links_categories_that_have_posts(): void
    {
        $this->switchOn();
        $events = BlogCategory::factory()->create(['name' => 'Events', 'slug' => 'events']);
        BlogCategory::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
        $inCategory = BlogPost::factory()->create();
        $inCategory->categories()->attach($events);
        BlogPost::factory()->draft()->create()->categories()->attach($events);
        BlogPost::factory()->create();

        $this->get(route('blog.category', $events))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Index')
                ->where('category.name', 'Events')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $inCategory->id)
                ->where('posts.data.0.categories.0.slug', 'events')
                ->where('categories', [['name' => 'Events', 'slug' => 'events']]));
        $this->get('/blog/category/missing')->assertNotFound();
    }

    public function test_category_pages_are_not_found_while_the_blog_is_off(): void
    {
        $category = BlogCategory::factory()->create();

        $this->get(route('blog.category', $category))->assertNotFound();
    }

    public function test_a_video_link_on_its_own_line_becomes_a_privacy_friendly_player(): void
    {
        $this->switchOn();
        $post = BlogPost::factory()->create(['body' => "Watch:\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n\nhttps://vimeo.com/76979871\n\n[Our talk](https://youtu.be/dQw4w9WgXcQ) is also online.\n\nhttps://example.com/watch?v=dQw4w9WgXcQ"]);

        $this->get(route('blog.show', $post))->assertInertia(fn (Assert $page) => $page
            ->where('post.html', function (string $html) {
                $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0"', $html);
                $this->assertStringContainsString('src="https://player.vimeo.com/video/76979871?dnt=1"', $html);
                // A link inside a sentence, and links to other sites, stay links.
                $this->assertStringContainsString('<a href="https://youtu.be/dQw4w9WgXcQ">Our talk</a>', $html);
                $this->assertSame(2, substr_count($html, '<iframe'));

                return true;
            }));
    }

    public function test_feed_lists_post_categories(): void
    {
        $this->switchOn();
        $post = BlogPost::factory()->create();
        $post->categories()->attach(BlogCategory::factory()->create(['name' => 'News & updates']));

        $xml = simplexml_load_string($this->get(route('blog.feed'))->getContent());
        $this->assertSame('News & updates', (string) $xml->channel->item[0]->category);
    }

    private function switchOn(): void
    {
        app(BlogSettings::class)->update(true, 'News', 'Updates from the team.');
    }
}
