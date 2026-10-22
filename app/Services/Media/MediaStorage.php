<?php

namespace App\Services\Media;

use App\Services\Integrations\IntegrationManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Where blog and campaign pictures are kept, chosen in Admin → Integrations → File storage & CDN:
 *
 * - **This server** (default): the `public` disk, at /storage/….
 * - **This server through a CDN** (`cdn` integration): the same files, linked through a pull-zone address.
 * - **An S3-compatible bucket** (`s3_storage` integration): AWS S3, Cloudflare R2, DigitalOcean Spaces,
 *   Backblaze B2, Wasabi and similar, optionally linked through a CDN address.
 *
 * Each saved file is a *reference*: a path on this server (`blog/abc.jpg`), or the same path with the
 * `s3:` prefix when it went to the bucket. Files keep working after the setting changes: new uploads go to
 * the new place and older ones are still served from where they were saved.
 */
class MediaStorage
{
    public const CDN = 'cdn';

    public const BUCKET = 's3_storage';

    public const SERVER_DISK = 'public';

    public const BUCKET_DISK = 'media_bucket';

    public const BUCKET_PREFIX = 's3:';

    /**
     * Fingerprint of the bucket settings the `media_bucket` disk was built with, so a long-running worker
     * picks up new settings.
     */
    private ?string $bucketFingerprint = null;

    public function __construct(private IntegrationManager $integrations) {}

    /**
     * Where new uploads go: `server`, `cdn` or `bucket`.
     */
    public function location(): string
    {
        return match (true) {
            $this->integrations->isEnabled(self::BUCKET) => 'bucket',
            $this->integrations->isEnabled(self::CDN) => 'cdn',
            default => 'server',
        };
    }

    /**
     * Save an upload under `$directory` in the current location and return its reference.
     *
     * @throws ValidationException when the file cannot be stored
     */
    public function store(UploadedFile $file, string $directory, string $errorKey = 'file'): string
    {
        $toBucket = $this->location() === 'bucket';
        $path = $file->store($directory, $toBucket ? $this->bucketDiskName() : self::SERVER_DISK);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([$errorKey => __('The file could not be stored. Please try again.')]);
        }

        return $toBucket ? self::BUCKET_PREFIX.$path : $path;
    }

    /**
     * The public address of a saved file.
     */
    public function url(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        if (str_starts_with($reference, self::BUCKET_PREFIX)) {
            $path = substr($reference, strlen(self::BUCKET_PREFIX));
            $base = $this->bucketPublicUrl();

            if ($base === null && $this->bucketConfig() === null) {
                return null;
            }

            return $base !== null ? $base.'/'.ltrim($path, '/') : $this->bucketDisk()->url($path);
        }

        $cdn = $this->cdnUrl();

        return $cdn !== null ? $cdn.'/'.ltrim($reference, '/') : Storage::disk(self::SERVER_DISK)->url($reference);
    }

    /**
     * The reference behind an address made by {@see url()} (for pictures linked in a post's text), or null
     * when the address points somewhere else. Addresses on this server match by path, so they still match
     * after the site moves to another domain.
     */
    public function referenceFromUrl(string $url): ?string
    {
        $bucketBase = $this->bucketPublicUrl();

        if ($bucketBase !== null && str_starts_with($url, $bucketBase.'/')) {
            return self::BUCKET_PREFIX.$this->cleanPath(substr($url, strlen($bucketBase) + 1));
        }

        $cdn = $this->cdnUrl();

        if ($cdn !== null && str_starts_with($url, $cdn.'/')) {
            return $this->cleanPath(substr($url, strlen($cdn) + 1));
        }

        $serverPath = rtrim((string) parse_url(Storage::disk(self::SERVER_DISK)->url(''), PHP_URL_PATH), '/').'/';
        $urlPath = (string) parse_url($url, PHP_URL_PATH);

        return $serverPath !== '/' && str_starts_with($urlPath, $serverPath)
            ? $this->cleanPath(substr($urlPath, strlen($serverPath)))
            : null;
    }

    /**
     * Delete saved files, wherever each one was stored. Missing files are ignored.
     *
     * @param  string|list<string>  $references
     */
    public function delete(string|array $references): void
    {
        $server = [];
        $bucket = [];

        foreach ((array) $references as $reference) {
            if (str_starts_with($reference, self::BUCKET_PREFIX)) {
                $bucket[] = substr($reference, strlen(self::BUCKET_PREFIX));
            } elseif ($reference !== '') {
                $server[] = $reference;
            }
        }

        if ($server !== []) {
            Storage::disk(self::SERVER_DISK)->delete($server);
        }

        if ($bucket !== [] && $this->bucketConfig() !== null) {
            $this->bucketDisk()->delete($bucket);
        }
    }

    /**
     * References of every file under `$directory`, in each place files may be, with when each was last changed.
     *
     * @return array<string, int> reference => Unix time
     */
    public function filesIn(string $directory): array
    {
        $files = [];
        $server = Storage::disk(self::SERVER_DISK);

        foreach ($server->files($directory) as $path) {
            $files[$path] = $server->lastModified($path);
        }

        if ($this->bucketConfig() !== null) {
            $bucket = $this->bucketDisk();

            foreach ($bucket->files($directory) as $path) {
                $files[self::BUCKET_PREFIX.$path] = $bucket->lastModified($path);
            }
        }

        return $files;
    }

    /**
     * The bucket as a filesystem, built from the saved settings (also used by the connection test).
     */
    public function bucketDisk(): Filesystem
    {
        return Storage::disk($this->bucketDiskName());
    }

    /**
     * Filesystem settings for a bucket, from the integration's field values.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function diskConfig(array $config): array
    {
        $endpoint = trim((string) ($config['endpoint'] ?? ''));

        return array_filter([
            'driver' => 's3',
            'key' => (string) ($config['access_key_id'] ?? ''),
            'secret' => (string) ($config['secret_access_key'] ?? ''),
            'region' => (string) ($config['region'] ?? '') ?: 'auto',
            'bucket' => (string) ($config['bucket'] ?? ''),
            'endpoint' => $endpoint !== '' ? rtrim($endpoint, '/') : null,
            'use_path_style_endpoint' => ($config['path_style'] ?? 'no') === 'yes',
            'url' => self::trimUrl($config['public_url'] ?? null),
            // Uploads are private objects: buckets that use "bucket owner enforced" ownership reject ACLs, so
            // visitors read files through a public bucket policy, a public bucket URL or the CDN address.
            'throw' => true,
            'report' => false,
        ], fn (mixed $value) => $value !== null);
    }

    private function bucketDiskName(): string
    {
        $config = $this->bucketConfig()
            ?? throw ValidationException::withMessages(['file' => __('The storage bucket is not set up.')]);
        $fingerprint = hash('sha256', serialize($config));

        if ($this->bucketFingerprint !== $fingerprint) {
            if ($this->bucketFingerprint !== null) {
                Storage::forgetDisk(self::BUCKET_DISK);
            }

            config(['filesystems.disks.'.self::BUCKET_DISK => self::diskConfig($config)]);
            $this->bucketFingerprint = $fingerprint;
        }

        return self::BUCKET_DISK;
    }

    /**
     * The bucket settings even while the bucket is switched off, so files saved there earlier keep working.
     *
     * @return array<string, mixed>|null
     */
    private function bucketConfig(): ?array
    {
        $config = $this->integrations->savedConfig(self::BUCKET);

        return filled($config['bucket'] ?? null) ? $config : null;
    }

    private function bucketPublicUrl(): ?string
    {
        return self::trimUrl($this->bucketConfig()['public_url'] ?? null);
    }

    private function cdnUrl(): ?string
    {
        return self::trimUrl($this->integrations->enabledConfig(self::CDN)['url'] ?? null);
    }

    private static function trimUrl(mixed $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        return $url === '' ? null : $url;
    }

    private function cleanPath(string $path): string
    {
        return ltrim(rawurldecode((string) strtok($path, '?#')), '/');
    }
}
