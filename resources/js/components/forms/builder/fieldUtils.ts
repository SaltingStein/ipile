import type { FormFieldDefinition, FormFieldTypeOption, FormFieldTypeValue } from '../../../types';

/**
 * What is being dragged in the builder: a new field from the palette, or an existing field being reordered.
 */
export type DragPayload = { kind: 'new'; type: FormFieldTypeValue } | { kind: 'move'; id: string };

const defaultLabels: Partial<Record<FormFieldTypeValue, string>> = {
    text: 'Your answer',
    textarea: 'Tell us more',
    email: 'Email',
    phone: 'Phone number',
    number: 'Number',
    date: 'Date',
    url: 'Website',
    select: 'Choose one',
    radio: 'Choose one',
    checkboxes: 'Choose any',
    checkbox: 'I agree',
    heading: 'Section title',
};

/**
 * A short random id. `crypto.randomUUID` is not available on plain-http development hosts, so fall back.
 */
export function newFieldId(): string {
    const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID().replace(/-/g, '').slice(0, 10)
        : Math.random().toString(36).slice(2, 12);

    return `f_${random}`;
}

/**
 * A field ID from a label: "Job title" → "job_title". Matches the server rule /^[a-z][a-z0-9_]*$/.
 */
export function toFieldKey(label: string): string {
    const key = label
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 34);

    return /^[a-z]/.test(key) ? key : `field_${key}`.replace(/_+$/, '');
}

/**
 * `base`, or `base_2`, `base_3`… when another field already uses it.
 */
export function uniqueKey(base: string, takenKeys: string[]): string {
    let candidate = base;
    let suffix = 2;

    while (takenKeys.includes(candidate)) {
        candidate = `${base}_${suffix}`;
        suffix += 1;
    }

    return candidate;
}

export function createField(type: FormFieldTypeOption, existing: FormFieldDefinition[]): FormFieldDefinition {
    const label = defaultLabels[type.value] ?? type.label;

    return {
        id: newFieldId(),
        key: uniqueKey(toFieldKey(label), existing.map((field) => field.key)),
        type: type.value,
        label,
        hint: null,
        placeholder: null,
        required: false,
        options: type.has_options ? ['Option 1', 'Option 2'] : [],
        min: null,
        max: null,
    };
}

/**
 * A copy placed after the original, with a new id and field ID.
 */
export function duplicateField(field: FormFieldDefinition, existing: FormFieldDefinition[]): FormFieldDefinition {
    return { ...field, id: newFieldId(), key: uniqueKey(`${field.key}_copy`.slice(0, 40), existing.map((other) => other.key)), options: [...field.options] };
}

/**
 * Move an item so it lands before position `dropIndex` (0…length) of the original list.
 */
export function moveItem<T>(items: T[], fromIndex: number, dropIndex: number): T[] {
    const next = [...items];
    const [moved] = next.splice(fromIndex, 1);
    next.splice(fromIndex < dropIndex ? dropIndex - 1 : dropIndex, 0, moved);

    return next;
}

/**
 * ISO timestamp → value for <input type="datetime-local"> in the browser's time zone.
 */
export function toLocalDateTimeInput(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

/**
 * <input type="datetime-local"> value → ISO timestamp, so the server stores the moment the person meant.
 */
export function fromLocalDateTimeInput(value: string): string | null {
    return value ? new Date(value).toISOString() : null;
}

export function hasPlaceholder(type: FormFieldTypeValue): boolean {
    return ['text', 'textarea', 'email', 'phone', 'number', 'url'].includes(type);
}

export function hasLimits(type: FormFieldTypeValue): boolean {
    return ['text', 'textarea', 'number'].includes(type);
}
