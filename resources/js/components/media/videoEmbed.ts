/**
 * YouTube and Vimeo links → privacy-friendly player addresses. Mirrors App\Services\Media\VideoEmbed on the
 * server, which builds the players visitors see; this copy is for previews while editing.
 */
export interface ParsedVideo {
    provider: 'youtube' | 'vimeo';
    id: string;
    embedUrl: string;
}

const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
const VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

export function parseVideoUrl(value: string | null | undefined): ParsedVideo | null {
    let url: URL;

    try {
        url = new URL((value ?? '').trim());
    } catch {
        return null;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }

    const host = url.hostname.toLowerCase();

    if (host === 'youtu.be' || YOUTUBE_HOSTS.includes(host)) {
        const id = host === 'youtu.be'
            ? url.pathname.replace(/^\/+|\/+$/g, '')
            : url.pathname === '/watch'
                ? (url.searchParams.get('v') ?? '')
                : (url.pathname.match(/^\/(?:embed|shorts|live|v)\/([^/]+)/)?.[1] ?? '');

        if (!/^[A-Za-z0-9_-]{11}$/.test(id)) {
            return null;
        }

        const start = seconds(url.searchParams.get('t') ?? url.searchParams.get('start'));

        return { provider: 'youtube', id, embedUrl: `https://www.youtube-nocookie.com/embed/${id}?rel=0${start > 0 ? `&start=${start}` : ''}` };
    }

    if (VIMEO_HOSTS.includes(host)) {
        const match = url.pathname.match(/^\/(?:video\/)?(\d{1,12})(?:\/([0-9a-f]{6,20}))?\/?$/i);

        if (!match) {
            return null;
        }

        const hash = (match[2] ?? url.searchParams.get('h') ?? '').toLowerCase();

        return { provider: 'vimeo', id: match[1], embedUrl: `https://player.vimeo.com/video/${match[1]}?dnt=1${/^[0-9a-f]{6,20}$/.test(hash) ? `&h=${hash}` : ''}` };
    }

    return null;
}

/**
 * "90", "90s" or "1m30s" → 90.
 */
function seconds(value: string | null): number {
    const match = value?.match(/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/);

    return match ? Math.min(Number(match[1] ?? 0) * 3600 + Number(match[2] ?? 0) * 60 + Number(match[3] ?? 0), 86400) : 0;
}
