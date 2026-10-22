import { useState } from 'react';
import { router } from '@inertiajs/react';

interface BlogCategoryPickerProps {
    categories: { id: number; name: string }[];
    selected: number[];
    onChange: (ids: number[]) => void;
    error?: string;
}

/**
 * Tick the categories a post belongs to, or add a new one without leaving the editor. The new category is
 * saved straight away (the post itself is saved with the post form) and ticked for this post.
 */
export default function BlogCategoryPicker({ categories, selected, onChange, error }: BlogCategoryPickerProps) {
    const [name, setName] = useState('');
    const [adding, setAdding] = useState(false);
    const [addError, setAddError] = useState<string | null>(null);

    function toggle(id: number, checked: boolean) {
        onChange(checked ? [...selected, id] : selected.filter((selectedId) => selectedId !== id));
    }

    function add() {
        const newName = name.trim();

        if (newName === '') {
            return;
        }

        setAdding(true);
        setAddError(null);
        router.post('/admin/blog/categories', { name: newName }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const created = (page.props.categories as BlogCategoryPickerProps['categories']).find((category) => category.name === newName);

                if (created) {
                    onChange([...selected, created.id]);
                }

                setName('');
            },
            onError: (errors) => setAddError(errors.name ?? 'The category could not be added.'),
            onFinish: () => setAdding(false),
        });
    }

    return (
        <div className="flex flex-col gap-3">
            {categories.length === 0 ? (
                <p className="text-sm text-slate-400">No categories yet. Add the first one below.</p>
            ) : (
                <fieldset className="flex max-h-56 flex-col gap-2 overflow-y-auto">
                    <legend className="sr-only">Categories for this post</legend>
                    {categories.map((category) => (
                        <label key={category.id} htmlFor={`post-category-${category.id}`} className="flex cursor-pointer items-center gap-2 text-sm text-slate-200">
                            <input id={`post-category-${category.id}`} type="checkbox" checked={selected.includes(category.id)}
                                onChange={(event) => toggle(category.id, event.target.checked)} className="size-4 accent-emerald-500" />
                            {category.name}
                        </label>
                    ))}
                </fieldset>
            )}
            {error && <p role="alert" className="text-sm text-rose-400">{error}</p>}
            <div className="flex flex-col gap-1">
                <label htmlFor="post-new-category" className="text-xs text-slate-400">Add a category</label>
                <div className="flex gap-2">
                    <input id="post-new-category" value={name} maxLength={60} onChange={(event) => setName(event.target.value)}
                        aria-invalid={addError ? true : undefined} aria-describedby={addError ? 'post-new-category-error' : undefined}
                        onKeyDown={(event) => {
                            // Enter adds the category instead of submitting the post form.
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                add();
                            }
                        }}
                        className="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none" />
                    <button type="button" onClick={add} disabled={adding || name.trim() === ''}
                        className="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-100 hover:border-slate-500 disabled:opacity-50">
                        {adding ? 'Adding…' : 'Add'}
                    </button>
                </div>
                {addError && <p id="post-new-category-error" role="alert" className="text-sm text-rose-400">{addError}</p>}
            </div>
        </div>
    );
}
