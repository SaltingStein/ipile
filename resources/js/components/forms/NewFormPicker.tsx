import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import Button from '../ui/Button';
import type { FormTemplateOption } from '../../types';

/**
 * Choose a template and create a draft form; the builder opens next.
 */
interface NewFormPickerProps {
    templates: FormTemplateOption[];
    createUrl: string;
}

export default function NewFormPicker({ templates, createUrl }: NewFormPickerProps) {
    const { data, setData, post, processing, errors } = useForm({ template: templates[0]?.value ?? 'blank' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post(createUrl);
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <fieldset className="grid gap-3 sm:grid-cols-2">
                <legend className="sr-only">Start from</legend>
                {templates.map((template) => (
                    <label key={template.value} htmlFor={`template-${template.value}`}
                        className={`flex cursor-pointer items-start gap-3 rounded-xl border p-4 text-sm transition ${data.template === template.value ? 'border-emerald-500 bg-emerald-500/5' : 'border-slate-800 hover:border-slate-600'}`}>
                        <input id={`template-${template.value}`} type="radio" name="template" value={template.value}
                            checked={data.template === template.value} onChange={() => setData('template', template.value)}
                            className="mt-0.5 size-4 accent-emerald-500" />
                        <span className="flex flex-col gap-0.5">
                            <span className="font-medium text-slate-100">{template.label}</span>
                            <span className="text-slate-400">{template.description}</span>
                        </span>
                    </label>
                ))}
            </fieldset>
            {errors.template && <p className="text-sm text-rose-400">{errors.template}</p>}
            <Button disabled={processing} className="self-start">{processing ? 'Creating…' : 'Create form'}</Button>
        </form>
    );
}
