import FormFieldInput from './FormFieldInput';
import type { FormAnswer, FormFieldDefinition } from '../../types';

/**
 * A list of form-builder fields bound to an `answers` object. Validation errors arrive as `answers.{key}` (or `answers.{key}.0`).
 */
interface FormFieldsProps {
    fields: FormFieldDefinition[];
    answers: Record<string, FormAnswer>;
    onChange: (key: string, value: FormAnswer) => void;
    errors: Record<string, string | undefined>;
    idPrefix?: string;
}

export default function FormFields({ fields, answers, onChange, errors, idPrefix }: FormFieldsProps) {
    const errorFor = (key: string) => errors[`answers.${key}`]
        ?? Object.entries(errors).find(([name]) => name.startsWith(`answers.${key}.`))?.[1];

    return (
        <>
            {fields.map((field) => (
                <FormFieldInput key={field.id} field={field} idPrefix={idPrefix}
                    value={answers[field.key]} error={errorFor(field.key)}
                    onChange={(value) => onChange(field.key, value)} />
            ))}
        </>
    );
}
