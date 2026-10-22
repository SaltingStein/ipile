<?php

namespace App\Services\Media;

/**
 * Turns YouTube and Vimeo links into privacy-friendly embedded players. Only the video id (and Vimeo's
 * unlisted-video hash and a start time) are taken from the link, so the embed address is always built
 * from known parts and never from the raw link.
 *
 * Used by blog posts (a video link on its own line becomes a player) and campaign pages.
 */
class VideoEmbed
{
    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    private const VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    /**
     * The video behind a link, or null when it is not a YouTube or Vimeo video link.
     *
     * @return array{provider: 'youtube'|'vimeo', id: string, embed_url: string, url: string}|null
     */
    public function parse(?string $url): ?array
    {
        $parts = parse_url(trim((string) $url));

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if ($host === 'youtu.be' || in_array($host, self::YOUTUBE_HOSTS, true)) {
            return $this->youtube($host, $path, $query);
        }

        if (in_array($host, self::VIMEO_HOSTS, true)) {
            return $this->vimeo($path, $query);
        }

        return null;
    }

    /**
     * The player as HTML. Every attribute is built from the parsed id, so it is safe to place in a page.
     *
     * @param  array{provider: string, embed_url: string}  $video
     */
    public function iframe(array $video, string $title = 'Video'): string
    {
        return sprintf(
            '<div class="video-embed"><iframe src="%s" title="%s" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>',
            e($video['embed_url']),
            e($title),
        );
    }

    /**
     * @param  array<mixed>  $query
     * @return array{provider: 'youtube', id: string, embed_url: string, url: string}|null
     */
    private function youtube(string $host, string $path, array $query): ?array
    {
        $id = match (true) {
            $host === 'youtu.be' => trim($path, '/'),
            $path === '/watch' => is_string($query['v'] ?? null) ? $query['v'] : '',
            default => preg_match('#^/(?:embed|shorts|live|v)/([^/]+)#', $path, $matches) === 1 ? $matches[1] : '',
        };

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) {
            return null;
        }

        $start = $this->seconds($query['t'] ?? $query['start'] ?? null);

        return [
            'provider' => 'youtube',
            'id' => $id,
            'embed_url' => "https://www.youtube-nocookie.com/embed/{$id}?rel=0".($start > 0 ? "&start={$start}" : ''),
            'url' => "https://www.youtube.com/watch?v={$id}".($start > 0 ? "&t={$start}" : ''),
        ];
    }

    /**
     * @param  array<mixed>  $query
     * @return array{provider: 'vimeo', id: string, embed_url: string, url: string}|null
     */
    private function vimeo(string $path, array $query): ?array
    {
        // vimeo.com/123, vimeo.com/123/abcdef (unlisted), player.vimeo.com/video/123?h=abcdef
        if (preg_match('#^/(?:video/)?(\d{1,12})(?:/([0-9a-f]{6,20}))?/?$#i', $path, $matches) !== 1) {
            return null;
        }

        $id = $matches[1];
        $hash = $matches[2] ?? (is_string($query['h'] ?? null) ? $query['h'] : '');
        $hash = preg_match('/^[0-9a-f]{6,20}$/i', $hash) === 1 ? strtolower($hash) : '';

        return [
            'provider' => 'vimeo',
            'id' => $id,
            'embed_url' => "https://player.vimeo.com/video/{$id}?dnt=1".($hash !== '' ? "&h={$hash}" : ''),
            'url' => "https://vimeo.com/{$id}".($hash !== '' ? "/{$hash}" : ''),
        ];
    }

    /**
     * "90", "90s" or "1m30s" → 90.
     */
    private function seconds(mixed $value): int
    {
        if (! is_string($value) || preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $value, $matches) !== 1) {
            return 0;
        }

        return min(((int) ($matches[1] ?? 0)) * 3600 + ((int) ($matches[2] ?? 0)) * 60 + (int) ($matches[3] ?? 0), 86400);
    }
}
