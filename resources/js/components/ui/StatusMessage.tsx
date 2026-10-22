/**
 * Success notice (flashed `status`) or error notice shown above a form.
 */
interface StatusMessageProps {
    message?: string | null;
    tone?: 'success' | 'error';
}

export default function StatusMessage({ message, tone = 'success' }: StatusMessageProps) {
    if (!message) {
        return null;
    }

    const toneClasses = tone === 'success'
        ? 'border-emerald-800 bg-emerald-950/40 text-emerald-200'
        : 'border-rose-800 bg-rose-950/40 text-rose-200';

    return (
        <p role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border px-4 py-3 text-sm ${toneClasses}`}>
            {message}
        </p>
    );
}
