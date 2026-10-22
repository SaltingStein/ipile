<?php

namespace Tests\Feature\Services;

use App\Models\BlogPost;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use App\Services\Media\MediaStorage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers MediaStorage: blog and campaign pictures on this server, through a CDN, or in a storage bucket.
 */
class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaStorage::SERVER_DISK);
        Storage::fake(MediaStorage::BUCKET_DISK);
    }

    public function test_files_stay_on_this_server_by_default(): void
    {
        $storage = app(MediaStorage::class);

        $reference = $storage->store(UploadedFile::fake()->image('a.jpg'), 'blog');

        $this->assertSame('server', $storage->location());
        $this->assertStringStartsWith('blog/', $reference);
        Storage::disk(MediaStorage::SERVER_DISK)->assertExists($reference);
        $this->assertSame(Storage::disk(MediaStorage::SERVER_DISK)->url($reference), $storage->url($reference));
    }

    public function test_cdn_address_serves_files_kept_on_this_server(): void
    {
        $this->saveIntegration(MediaStorage::CDN, ['url' => 'https://cdn.example.com/storage/']);
        $storage = app(MediaStorage::class);

        $reference = $storage->store(UploadedFile::fake()->image('a.jpg'), 'blog');

        $this->assertSame('cdn', $storage->location());
        Storage::disk(MediaStorage::SERVER_DISK)->assertExists($reference);
        $this->assertSame("https://cdn.example.com/storage/{$reference}", $storage->url($reference));
        $this->assertSame($reference, $storage->referenceFromUrl("https://cdn.example.com/storage/{$reference}"));
    }

    public function test_bucket_takes_new_uploads_and_older_files_keep_working(): void
    {
        $storage = app(MediaStorage::class);
        $before = $storage->store(UploadedFile::fake()->image('old.jpg'), 'blog');

        $this->saveIntegration(MediaStorage::BUCKET, $this->bucketConfig());
        $after = $storage->store(UploadedFile::fake()->image('new.jpg'), 'blog');

        $this->assertSame('bucket', $storage->location());
        $this->assertStringStartsWith(MediaStorage::BUCKET_PREFIX.'blog/', $after);
        $path = substr($after, strlen(MediaStorage::BUCKET_PREFIX));
        Storage::disk(MediaStorage::BUCKET_DISK)->assertExists($path);
        Storage::disk(MediaStorage::SERVER_DISK)->assertMissing($path);
        $this->assertSame("https://media.example.com/{$path}", $storage->url($after));
        $this->assertSame($after, $storage->referenceFromUrl("https://media.example.com/{$path}"));
        // The picture saved before the switch is still served from this server.
        $this->assertSame(Storage::disk(MediaStorage::SERVER_DISK)->url($before), $storage->url($before));

        $storage->delete([$before, $after]);
        Storage::disk(MediaStorage::SERVER_DISK)->assertMissing($before);
        Storage::disk(MediaStorage::BUCKET_DISK)->assertMissing($path);
    }

    public function test_bucket_files_still_work_after_the_bucket_is_switched_off(): void
    {
        $this->saveIntegration(MediaStorage::BUCKET, $this->bucketConfig());
        $storage = app(MediaStorage::class);
        $reference = $storage->store(UploadedFile::fake()->image('a.jpg'), 'blog');

        $this->saveIntegration(MediaStorage::BUCKET, $this->bucketConfig(), enabled: false);

        $this->assertSame('server', $storage->location());
        $this->assertSame('https://media.example.com/'.substr($reference, strlen(MediaStorage::BUCKET_PREFIX)), $storage->url($reference));
    }

    public function test_blog_pictures_go_to_the_bucket_and_are_deleted_from_it_with_the_post(): void
    {
        $this->saveIntegration(MediaStorage::BUCKET, $this->bucketConfig());
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_blog');

        $url = $this->actingAs($manager)->postJson(route('admin.blog.images.store'), ['image' => UploadedFile::fake()->image('hall.png')])
            ->assertCreated()->json('url');
        $this->assertStringStartsWith('https://media.example.com/blog/content/', $url);
        $path = substr($url, strlen('https://media.example.com/'));
        Storage::disk(MediaStorage::BUCKET_DISK)->assertExists($path);

        $post = BlogPost::factory()->create(['body' => "![Hall]({$url})"]);
        $this->actingAs($manager)->delete(route('admin.blog.destroy', $post));

        Storage::disk(MediaStorage::BUCKET_DISK)->assertMissing($path);
    }

    public function test_bucket_settings_need_the_bucket_and_keys_and_secrets_stay_hidden(): void
    {
        $definition = app(IntegrationCatalog::class)->find(MediaStorage::BUCKET);

        $this->assertNotNull($definition);
        $this->assertTrue($definition->field('secret_access_key')->secret);
        $this->assertTrue($definition->field('access_key_id')->secret);
        $this->assertFalse($definition->field('endpoint')->required);
        $this->assertSame([
            'driver' => 's3',
            'key' => 'AKIDEXAMPLE',
            'secret' => 'secret',
            'region' => 'auto',
            'bucket' => 'site-media',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => false,
            'url' => 'https://media.example.com',
            'throw' => true,
            'report' => false,
        ], MediaStorage::diskConfig($this->bucketConfig()));
    }

    /**
     * @return array<string, string>
     */
    private function bucketConfig(): array
    {
        return [
            'bucket' => 'site-media',
            'region' => 'auto',
            'endpoint' => 'https://account.r2.cloudflarestorage.com/',
            'access_key_id' => 'AKIDEXAMPLE',
            'secret_access_key' => 'secret',
            'public_url' => 'https://media.example.com/',
            'path_style' => 'no',
        ];
    }

    /**
     * @param  array<string, string>  $config
     */
    private function saveIntegration(string $provider, array $config, bool $enabled = true): void
    {
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find($provider), ['is_enabled' => $enabled, 'config' => $config]);
        $this->assertSame($enabled, Integration::query()->where('provider', $provider)->sole()->is_enabled);
    }
}
