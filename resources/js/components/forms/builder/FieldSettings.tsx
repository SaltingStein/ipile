import Checkbox from '../../ui/Checkbox';
import TextArea from '../../ui/TextArea';
import TextField from '../../ui/TextField';
import { hasLimits, hasPlaceholder } from './fieldUtils';
import type { FormFieldDefinition, FormFieldTypeOption } from '../../../types';

/**
 * Settings for the selected field. Errors use the server's `fields.{index}.{setting}` names.
 */
interface FieldSettingsProps {
    field: FormFieldDefinition;
    index: number;
    fieldType: FormFieldTypeOption | undefined;
    errors: Record<string, string | undefined>;
    hasResponses: boolean;
    onChange: (changes: Partial<FormFieldDefinition>) => void;
    onKeyChange: (key: string) => void;
}

export default function FieldSettings({ field, index, fieldType, errors, hasResponses, onChange, onKeyChange }: FieldSettingsProps) {
    const error = (setting: string) => errors[`fields.${index}.${setting}`]
        ?? Object.entries(errors).find(([name]) => name.startsWith(`fields.${index}.${setting}.`))?.[1];
    const isHeading = field.type === 'heading';
    const limitNoun = field.type === 'number' ? 'value' : 'characters';
    const toNumber = (value: string) => (value === '' ? null : Number(value));

    return (
        <div className="flex flex-col gap-4">
            <p className="text-xs uppercase tracking-wide text-slate-400">{fieldType?.label ?? field.type}</p>

            {isHeading ? (
                <TextField label="Heading" name="field-label" value={field.label} maxLength={200} required
                    onChange={(label) => onChange({ label })} error={error('label')} />
            ) : (
                <TextArea label="Question" name="field-label" rows={2} value={field.label} maxLength={200} required
                    onChange={(label) => onChange({ label })} error={error('label')} />
            )}

            <TextArea label={isHeading ? 'Text below the heading (optional)' : 'Help text (optional)'} name="field-hint" rows={2}
                value={field.hint ?? ''} maxLength={1000} onChange={(hint) => onChange({ hint: hint || null })} error={error('hint')} />

            {fieldType?.has_options && (
                <TextArea label="Options, one per line" name="field-options" rows={Math.min(Math.max(field.options.length + 1, 3), 10)}
                    value={field.options.join('\n')}
                    hint="Paste a whole list at once if you like. Up to 50 options."
                    onChange={(value) => onChange({ options: value.split('\n') })} error={error('options')} />
            )}

            {hasPlaceholder(field.type) && (
                <TextField label="Placeholder (optional)" name="field-placeholder" value={field.placeholder ?? ''} maxLength={120}
                    hint="Example text shown inside the empty box." onChange={(placeholder) => onChange({ placeholder: placeholder || null })}
                    error={error('placeholder')} />
            )}

            {hasLimits(field.type) && (
                <div className="grid grid-cols-2 gap-3">
                    <TextField label={`Minimum ${limitNoun}`} name="field-min" type="number" value={field.min?.toString() ?? ''}
                        onChange={(value) => onChange({ min: toNumber(value) })} error={error('min')} />
                    <TextField label={`Maximum ${limitNoun}`} name="field-max" type="number" value={field.max?.toString() ?? ''}
                        onChange={(value) => onChange({ max: toNumber(value) })} error={error('max')} />
                </div>
            )}

            {!isHeading && (
                <Checkbox label="Required" name="field-required" checked={field.required}
                    description="People must answer before they can send the form."
                    onChange={(required) => onChange({ required })} />
            )}

            <details className="rounded-lg border border-slate-800 p-3 text-sm">
                <summary className="cursor-pointer text-slate-300">Advanced</summary>
                <div className="mt-3">
                    <TextField label="Field ID" name="field-key" value={field.key} maxLength={40} required
                        hint={hasResponses
                            ? 'Used in exports and the API. Changing it moves earlier answers to a “removed” column.'
                            : 'Used in exports and the API. Lowercase letters, numbers and underscores.'}
                        onChange={(key) => onKeyChange(key.toLowerCase())} error={error('key')} />
                </div>
            </details>
        </div>
    );
}
