<?php

namespace Tests\Feature\Admin;

use App\Models\BlogPost;
use App\Models\User;
use App\Services\Blog\BlogPostStatus;
use App\Services\Blog\BlogSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers Admin\BlogPostController: the blog switch, writing, scheduling and deleting posts.
 */
class BlogPostControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_people_without_manage_blog_cannot_open_the_blog_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->givePermissionTo('manage_settings');
        $post = BlogPost::factory()->create();

        $this->get(route('admin.blog.index'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('admin.blog.index'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.blog.settings.update'), ['enabled' => true, 'title' => 'Blog'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.blog.store'), $this->payload())->assertForbidden();
        $this->actingAs($user)->delete(route('admin.blog.destroy', $post))->assertForbidden();
        $this->assertFalse(app(BlogSettings::class)->enabled());
        $this->assertModelExists($post);
    }

    public function test_manager_turns_the_blog_on_and_names_it(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->put(route('admin.blog.settings.update'), ['enabled' => true, 'title' => 'Stories', 'description' => 'From our events.'])
            ->assertSessionHas('status', 'Blog settings saved. The blog is on.');

        $this->assertSame(['enabled' => true, 'title' => 'Stories', 'description' => 'From our events.'], app(BlogSettings::class)->all());
        $this->actingAs($manager)->get(route('admin.blog.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Blog/Index')
            ->where('settings.enabled', true));
    }

    public function test_publishing_without_a_time_publishes_now_with_the_cover_and_author(): void
    {
        $manager = $this->manager();

        $response = $this->actingAs($manager)->post(route('admin.blog.store'), $this->payload([
            'status' => 'published',
            'cover' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
        ]));

        $post = BlogPost::query()->sole();
        $response->assertRedirect(route('admin.blog.edit', $post))->assertSessionHas('status', 'Saved. The post is published.');
        $this->assertSame($manager->id, $post->author_id);
        $this->assertTrue($post->isVisible());
        Storage::disk('public')->assertExists($post->cover_image_path);
    }

    public function test_a_future_time_schedules_the_post(): void
    {
        $this->actingAs($this->manager())->post(route('admin.blog.store'), $this->payload([
            'status' => 'published',
            'published_at' => now()->addDays(3)->toIso8601String(),
        ]));

        $post = BlogPost::query()->sole();
        $this->assertTrue($post->isScheduled());
        $this->assertFalse($post->isVisible());
    }

    public function test_drafts_keep_no_publish_time(): void
    {
        $this->actingAs($this->manager())->post(route('admin.blog.store'), $this->payload())->assertSessionHas('status', 'Draft saved.');

        $post = BlogPost::query()->sole();
        $this->assertSame(BlogPostStatus::Draft, $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_addresses_must_be_unique_well_formed_and_not_reserved(): void
    {
        $manager = $this->manager();
        BlogPost::factory()->create(['slug' => 'taken']);

        foreach (['taken', 'feed', 'Not A Slug'] as $slug) {
            $this->actingAs($manager)->post(route('admin.blog.store'), $this->payload(['slug' => $slug]))->assertSessionHasErrors('slug');
        }

        $this->assertDatabaseCount('blog_posts', 1);
    }

    public function test_replacing_and_removing_the_cover_deletes_old_files(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->post(route('admin.blog.store'), $this->payload(['cover' => UploadedFile::fake()->image('first.jpg')]));
        $post = BlogPost::query()->sole();
        $firstCover = $post->cover_image_path;

        $this->actingAs($manager)->post(route('admin.blog.update', $post), $this->payload(['slug' => $post->slug, 'cover' => UploadedFile::fake()->image('second.jpg')]))
            ->assertSessionHasNoErrors();
        $secondCover = $post->fresh()->cover_image_path;
        Storage::disk('public')->assertMissing($firstCover);
        Storage::disk('public')->assertExists($secondCover);

        $this->actingAs($manager)->post(route('admin.blog.update', $post), $this->payload(['slug' => $post->slug, 'remove_cover' => true]))
            ->assertSessionHasNoErrors();
        $this->assertNull($post->fresh()->cover_image_path);
        Storage::disk('public')->assertMissing($secondCover);
    }

    public function test_deleting_a_post_removes_its_cover(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->post(route('admin.blog.store'), $this->payload(['cover' => UploadedFile::fake()->image('cover.jpg')]));
        $post = BlogPost::query()->sole();

        $this->actingAs($manager)->delete(route('admin.blog.destroy', $post))->assertRedirect(route('admin.blog.index'));

        $this->assertModelMissing($post);
        Storage::disk('public')->assertMissing($post->cover_image_path);
    }

    public function test_editor_uploads_body_images_and_returns_their_address(): void
    {
        $response = $this->actingAs($this->manager())
            ->postJson(route('admin.blog.images.store'), ['image' => UploadedFile::fake()->image('hall.png', 1200, 800)]);

        $response->assertCreated();
        $path = str_replace(Storage::disk('public')->url(''), '', $response->json('url'));
        $this->assertStringStartsWith('blog/content/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_body_image_upload_accepts_only_images_from_blog_managers(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->postJson(route('admin.blog.images.store'), ['image' => UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->actingAs($manager)->postJson(route('admin.blog.images.store'), ['image' => UploadedFile::fake()->create('notes.pdf', 5, 'application/pdf')])
            ->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson(route('admin.blog.images.store'), ['image' => UploadedFile::fake()->image('a.png')])
            ->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles('blog/content'));
    }

    public function test_deleting_a_post_removes_its_body_pictures_unless_another_post_uses_them(): void
    {
        $manager = $this->manager();
        $own = $this->uploadBodyImage($manager);
        $shared = $this->uploadBodyImage($manager);
        $post = BlogPost::factory()->create(['body' => $this->bodyWith($own, $shared)]);
        BlogPost::factory()->create(['body' => $this->bodyWith($shared)]);

        $this->actingAs($manager)->delete(route('admin.blog.destroy', $post))->assertRedirect(route('admin.blog.index'));

        Storage::disk('public')->assertMissing($own);
        Storage::disk('public')->assertExists($shared);
    }

    public function test_pictures_taken_out_of_a_post_are_deleted_when_it_is_saved(): void
    {
        $manager = $this->manager();
        $kept = $this->uploadBodyImage($manager);
        $removed = $this->uploadBodyImage($manager);
        $this->actingAs($manager)->post(route('admin.blog.store'), $this->payload(['body' => $this->bodyWith($kept, $removed)]));
        $post = BlogPost::query()->sole();

        $this->actingAs($manager)->post(route('admin.blog.update', $post), $this->payload(['body' => $this->bodyWith($kept)]))->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists($kept);
        Storage::disk('public')->assertMissing($removed);
    }

    public function test_daily_clean_up_deletes_old_pictures_no_post_uses(): void
    {
        $manager = $this->manager();
        $used = $this->uploadBodyImage($manager);
        $abandoned = $this->uploadBodyImage($manager);
        BlogPost::factory()->create(['body' => $this->bodyWith($used)]);

        // Pictures uploaded in the last day are kept: someone may still be writing the post.
        Artisan::call('blog:prune-images');
        Storage::disk('public')->assertExists($abandoned);

        $this->travel(25)->hours();
        Artisan::call('blog:prune-images');
        Storage::disk('public')->assertExists($used);
        Storage::disk('public')->assertMissing($abandoned);
    }

    public function test_preview_renders_markdown_safely(): void
    {
        $this->actingAs($this->manager())
            ->postJson(route('admin.blog.preview'), ['body' => "# Hello\n\n<img src=x onerror=alert(1)>"])
            ->assertOk()
            ->assertJson(fn ($json) => $json->where('html', fn (string $html) => str_contains($html, '<h1>Hello</h1>') && ! str_contains($html, 'onerror')));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Our first event',
            'slug' => 'our-first-event',
            'excerpt' => null,
            'body' => "## What happened\n\nIt was great.",
            'status' => 'draft',
            'published_at' => null,
            ...$overrides,
        ];
    }

    private function uploadBodyImage(User $manager): string
    {
        $url = $this->actingAs($manager)
            ->postJson(route('admin.blog.images.store'), ['image' => UploadedFile::fake()->image('photo.png')])
            ->assertCreated()
            ->json('url');

        return str_replace(Storage::disk('public')->url(''), '', $url);
    }

    private function bodyWith(string ...$paths): string
    {
        return collect($paths)->map(fn (string $path) => '![Photo]('.Storage::disk('public')->url($path).')')->implode("\n\n");
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_blog');
    }
}
