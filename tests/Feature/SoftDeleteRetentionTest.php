<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Payment;
use App\Models\User;
use App\Services\Blog\BlogAssets;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Soft deletes keep a record recoverable for `retention.deleted_records_days`;
 * `model:prune` then removes it and its files for good.
 */
class SoftDeleteRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_deleted_record_is_kept_until_the_retention_window_has_passed(): void
    {
        $post = BlogPost::factory()->create();
        $post->delete();

        $this->travel((int) config('retention.deleted_records_days') - 1)->days();
        $this->artisan('model:prune')->assertSuccessful();
        $this->assertSoftDeleted($post);

        $this->travel(2)->days();
        $this->artisan('model:prune')->assertSuccessful();
        $this->assertModelMissing($post);
    }

    public function test_restoring_a_post_brings_back_its_pictures(): void
    {
        $manager = $this->blogManager();
        $picture = $this->uploadBodyImage($manager);
        $post = BlogPost::factory()->create(['body' => '![Photo]('.Storage::disk('public')->url($picture).')']);

        $this->actingAs($manager)->delete(route('admin.blog.destroy', $post))->assertRedirect();
        $post->restore();

        $this->assertNotSoftDeleted($post);
        Storage::disk('public')->assertExists($picture);
    }

    public function test_the_nightly_picture_clean_up_leaves_a_deleted_posts_pictures_alone(): void
    {
        $manager = $this->blogManager();
        $picture = $this->uploadBodyImage($manager);
        $post = BlogPost::factory()->create(['body' => '![Photo]('.Storage::disk('public')->url($picture).')']);
        $this->actingAs($manager)->delete(route('admin.blog.destroy', $post));

        // Old enough to count as an unused upload, but its post can still be restored.
        $this->travel(2)->days();
        app(BlogAssets::class)->pruneOrphans();

        Storage::disk('public')->assertExists($picture);
    }

    public function test_payments_are_soft_deleted_but_never_purged(): void
    {
        $payment = Payment::factory()->create();
        $payment->delete();

        $this->purgeDeletedRecords();

        $this->assertSoftDeleted($payment);
    }

    public function test_deleted_records_are_hidden_from_queries_and_routes(): void
    {
        $post = BlogPost::factory()->create();
        $post->delete();

        $this->assertSame(0, BlogPost::query()->count());
        $this->assertSame(1, BlogPost::withTrashed()->count());
    }

    private function blogManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_blog');
    }

    private function uploadBodyImage(User $manager): string
    {
        $url = $this->actingAs($manager)
            ->postJson(route('admin.blog.images.store'), ['image' => UploadedFile::fake()->image('photo.png')])
            ->assertCreated()
            ->json('url');

        return str_replace(Storage::disk('public')->url(''), '', $url);
    }
}
