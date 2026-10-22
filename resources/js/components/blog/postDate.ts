/**
 * A post's publish date for readers, e.g. "19 October 2026".
 */
export function formatPostDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : '';
}
