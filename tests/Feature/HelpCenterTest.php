<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Help\HelpCenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    private string $articles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->articles = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists($this->articles);
        $this->app->instance(HelpCenter::class, new HelpCenter($this->articles));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->articles);

        parent::tearDown();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }

    public function test_articles_are_listed_in_order_and_rendered_from_markdown(): void
    {
        $this->article('second', "---\ntitle: Second\nsummary: Comes later.\norder: 20\n---\nBody.");
        $this->article('first', "---\ntitle: \"First steps\"\nsummary: Start here.\norder: 10\n---\n## Hello\n\nSee **this**.");

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('help.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Help/Index')
                ->where('articles', [
                    ['slug' => 'first', 'title' => 'First steps', 'summary' => 'Start here.'],
                    ['slug' => 'second', 'title' => 'Second', 'summary' => 'Comes later.'],
                ]));

        $this->get(route('help.show', 'first'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Help/Show')
                ->where('article.title', 'First steps')
                ->where('article.html', "<h2>Hello</h2>\n<p>See <strong>this</strong>.</p>\n"));
    }

    public function test_raw_html_and_unsafe_links_are_removed(): void
    {
        $this->article('unsafe', "---\ntitle: Unsafe\n---\n<script>alert(1)</script>\n\n[Click](javascript:alert(1))");

        $this->actingAs(User::factory()->create())->get(route('help.show', 'unsafe'))
            ->assertInertia(fn (Assert $page) => $page->where('article.html', fn (string $html) => ! str_contains($html, '<script') && ! str_contains($html, 'javascript:')));
    }

    public function test_articles_with_a_permission_are_hidden_from_users_without_it(): void
    {
        $this->article('billing', "---\ntitle: Billing\npermission: view_billing\n---\nInvoices.");
        $this->article('public', "---\ntitle: Public\n---\nFor everyone.");

        $member = User::factory()->create();
        $this->actingAs($member)->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.slug', 'public'));
        $this->get(route('help.show', 'billing'))->assertNotFound();

        $member->givePermissionTo('view_billing');
        $this->get(route('help.show', 'billing'))->assertOk();
    }

    public function test_unknown_slugs_and_files_without_front_matter_are_handled(): void
    {
        $this->article('plain-notes', 'No front matter here.');
        File::put($this->articles.'/Not_A_Slug.md', "---\ntitle: Ignored\n---\nx");

        $this->actingAs(User::factory()->create())->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.title', 'Plain notes'));
        $this->get(route('help.show', 'missing'))->assertNotFound();
    }

    public function test_draft_articles_only_show_while_running_locally(): void
    {
        $this->article('upcoming', "---\ntitle: Upcoming\ndraft: true\n---\nNot ready.");
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('help.index'))->assertInertia(fn (Assert $page) => $page->has('articles', 0));
        $this->get(route('help.show', 'upcoming'))->assertNotFound();

        $this->app->detectEnvironment(fn () => 'local');
        $this->get(route('help.show', 'upcoming'))->assertOk();
    }

    public function test_the_shipped_starter_articles_load(): void
    {
        $this->app->forgetInstance(HelpCenter::class);

        $this->actingAs(User::factory()->create())->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page->where('articles.0.slug', 'getting-started'));
        $this->get(route('help.show', 'two-factor-authentication'))->assertOk();
    }

    private function article(string $slug, string $contents): void
    {
        File::put("{$this->articles}/{$slug}.md", $contents);
    }
}
