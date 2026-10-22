import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Button from '../../ui/Button';

/**
 * Shown when a form or campaign page is published (and from the Share button): copy or open the link, and add
 * it to a menu.
 * Adding to a menu needs `manage_settings`; `menus` is null for everyone else.
 */
interface ShareFormDialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    url: string;
    menus: { path: string; site: boolean; workspace: boolean } | null;
    heading?: string;
}

export default function ShareFormDialog({ open, onClose, title, url, menus, heading = 'Your form is live' }: ShareFormDialogProps) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [copied, setCopied] = useState(false);
    const [adding, setAdding] = useState<string | null>(null);

    useEffect(() => {
        if (open && !dialog.current?.open) {
            dialog.current?.showModal();
            setCopied(false);
        } else if (!open && dialog.current?.open) {
            dialog.current.close();
        }
    }, [open]);

    async function copy() {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
        } catch {
            window.prompt('Copy this link:', url);
        }
    }

    function addToMenu(menu: 'site' | 'workspace') {
        if (!menus) {
            return;
        }

        router.post('/admin/navigation/links', { menu, label: title.slice(0, 40), href: menus.path }, {
            preserveScroll: true,
            onStart: () => setAdding(menu),
            onFinish: () => setAdding(null),
        });
    }

    return (
        <dialog ref={dialog} onClose={onClose} aria-labelledby="share-form-title"
            className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-2xl border border-slate-700 bg-slate-900 p-0 text-slate-100 backdrop:bg-slate-950/80">
            <div className="flex flex-col gap-5 p-6">
                <div className="flex flex-col gap-1">
                    <h2 id="share-form-title" className="text-xl font-semibold">{heading}</h2>
                    <p className="text-sm text-slate-400">Share the link anywhere: email, WhatsApp, social media or your website.</p>
                </div>

                <div className="flex flex-col gap-2">
                    <label htmlFor="share-form-url" className="text-sm font-medium text-slate-200">Form link</label>
                    <div className="flex gap-2">
                        <input id="share-form-url" readOnly value={url} onFocus={(event) => event.target.select()}
                            className="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100" />
                        <Button type="button" onClick={copy}>{copied ? 'Copied' : 'Copy link'}</Button>
                    </div>
                    <a href={url} target="_blank" rel="noreferrer" className="self-start text-sm text-emerald-400 hover:text-emerald-300">Open form in a new tab ↗</a>
                </div>

                <div className="flex flex-col gap-2 border-t border-slate-800 pt-4">
                    <h3 className="text-sm font-medium text-slate-200">Add to a menu</h3>
                    {menus ? (
                        <div className="flex flex-col gap-2">
                            <MenuButton added={menus.site} busy={adding === 'site'} onClick={() => addToMenu('site')}
                                label="Public site menu" description="The header on your landing page and other public pages." />
                            <MenuButton added={menus.workspace} busy={adding === 'workspace'} onClick={() => addToMenu('workspace')}
                                label="Workspace sidebar" description="For signed-in people, e.g. an internal form." />
                            <p className="text-xs text-slate-400">Rename, reorder or remove links in Admin → Menus.</p>
                        </div>
                    ) : (
                        <p className="text-sm text-slate-400">Site menus are managed by your site's administrators. Send them the link above if you would like it in the site menu.</p>
                    )}
                </div>

                <Button type="button" variant="secondary" onClick={onClose} className="self-end">Done</Button>
            </div>
        </dialog>
    );
}

interface MenuButtonProps {
    added: boolean;
    busy: boolean;
    onClick: () => void;
    label: string;
    description: string;
}

function MenuButton({ added, busy, onClick, label, description }: MenuButtonProps) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-slate-800 p-3">
            <span className="flex flex-col">
                <span className="text-sm font-medium text-slate-100">{label}</span>
                <span className="text-xs text-slate-400">{description}</span>
            </span>
            {added ? (
                <span className="shrink-0 text-sm text-emerald-300">✓ Added</span>
            ) : (
                <Button type="button" variant="secondary" disabled={busy} onClick={onClick} className="shrink-0">{busy ? 'Adding…' : 'Add'}</Button>
            )}
        </div>
    );
}
