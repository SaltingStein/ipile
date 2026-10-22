<?php

namespace Tests\Feature\Admin;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Admin\BlogCategoryController and choosing categories on a post.
 */
class BlogCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_without_manage_blog_cannot_change_categories(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->givePermissionTo('manage_settings');
        $category = BlogCategory::factory()->create();

        $this->actingAs($user)->post(route('admin.blog.categories.store'), ['name' => 'Events'])->assertForbidden();
        $this->actingAs($user)->put(route('admin.blog.categories.update', $category), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.blog.categories.destroy', $category))->assertForbidden();

        $this->assertDatabaseCount('blog_categories', 1);
        $this->assertSame($category->name, $category->fresh()->name);
    }

    public function test_manager_adds_a_category_with_a_unique_address(): void
    {
        $manager = $this->manager();
        BlogCategory::factory()->create(['name' => 'Old events', 'slug' => 'events-news']);

        $this->actingAs($manager)->post(route('admin.blog.categories.store'), ['name' => 'Events & news', 'description' => 'What is on.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Category “Events & news” added.');

        $category = BlogCategory::query()->where('name', 'Events & news')->sole();
        $this->assertSame('events-news-2', $category->slug);
        $this->assertSame('What is on.', $category->description);
    }

    public function test_names_must_be_unique_and_the_number_of_categories_is_capped(): void
    {
        $manager = $this->manager();
        BlogCategory::factory()->create(['name' => 'Events']);

        $this->actingAs($manager)->post(route('admin.blog.categories.store'), ['name' => 'Events'])->assertSessionHasErrors('name');

        BlogCategory::factory()->count(BlogCategory::MAX_CATEGORIES - 1)->create();
        $this->actingAs($manager)->post(route('admin.blog.categories.store'), ['name' => 'One too many'])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('blog_categories', BlogCategory::MAX_CATEGORIES);
    }

    public function test_renaming_keeps_the_address_and_removing_keeps_the_posts(): void
    {
        $manager = $this->manager();
        $category = BlogCategory::factory()->create(['name' => 'Events', 'slug' => 'events']);
        $post = BlogPost::factory()->create();
        $post->categories()->attach($category);

        $this->actingAs($manager)->put(route('admin.blog.categories.update', $category), ['name' => 'Gatherings'])->assertSessionHasNoErrors();
        $this->assertSame(['Gatherings', 'events'], [$category->fresh()->name, $category->fresh()->slug]);

        $this->actingAs($manager)->delete(route('admin.blog.categories.destroy', $category))->assertSessionHasNoErrors();
        $this->assertModelMissing($category);
        $this->assertModelExists($post);
        $this->assertCount(0, $post->categories()->get());
    }

    public function test_saving_a_post_sets_its_categories_and_an_empty_list_clears_them(): void
    {
        $manager = $this->manager();
        [$events, $news] = BlogCategory::factory()->count(2)->create();

        $this->actingAs($manager)->post(route('admin.blog.store'), $this->payload(['category_ids' => [$events->id, $news->id]]))->assertSessionHasNoErrors();
        $post = BlogPost::query()->sole();
        $this->assertEqualsCanonicalizing([$events->id, $news->id], $post->categories()->pluck('blog_categories.id')->all());

        // Browsers leave out an empty list, so a save without category_ids removes every category.
        $this->actingAs($manager)->post(route('admin.blog.update', $post), $this->payload())->assertSessionHasNoErrors();
        $this->assertCount(0, $post->categories()->get());

        $this->actingAs($manager)->post(route('admin.blog.update', $post), $this->payload(['category_ids' => [999]]))->assertSessionHasErrors('category_ids.0');
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
            'body' => 'It was great.',
            'status' => 'draft',
            ...$overrides,
        ];
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_blog');
    }
}
