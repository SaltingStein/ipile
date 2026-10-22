import Button from '../../ui/Button';
import Checkbox from '../../ui/Checkbox';
import TextArea from '../../ui/TextArea';
import TextField from '../../ui/TextField';
import type { FormStatusValue } from '../../../types';

export interface FormSettingsData {
    title: string;
    description: string;
    slug: string;
    status: FormStatusValue;
    submit_label: string;
    success_message: string;
    max_submissions: string;
    closes_at: string;
    sends_confirmation: boolean;
}

/**
 * Settings for the whole form: title, address, status, limits and the thank-you message.
 * The sign-up form only has a name and a status; the sign-up page decides everything else.
 */
interface FormSettingsPanelProps {
    data: FormSettingsData;
    errors: Record<string, string | undefined>;
    statuses: { value: FormStatusValue; label: string }[];
    isRegistration: boolean;
    onChange: <Key extends keyof FormSettingsData>(key: Key, value: FormSettingsData[Key]) => void;
    onDelete?: () => void;
}

const statusHelp: Record<FormStatusValue, string> = {
    draft: 'Only people who manage this form can see it.',
    published: 'Anyone with the link can fill it in.',
    closed: 'The page stays up but no longer accepts responses.',
};

const registrationStatusHelp: Partial<Record<FormStatusValue, string>> = {
    draft: 'Not shown yet. Build your questions, then publish.',
    published: 'Shown on the sign-up page, below name, email and password.',
};

export default function FormSettingsPanel({ data, errors, statuses, isRegistration, onChange, onDelete }: FormSettingsPanelProps) {
    const availableStatuses = isRegistration ? statuses.filter((status) => status.value !== 'closed') : statuses;
    const publicBase = `${window.location.origin}/f/`;

    return (
        <div className="flex flex-col gap-4">
            <fieldset className="flex flex-col gap-2">
                <legend className="mb-1 text-sm font-medium text-slate-200">Status</legend>
                {availableStatuses.map((status) => (
                    <label key={status.value} htmlFor={`form-status-${status.value}`}
                        className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm ${data.status === status.value ? 'border-emerald-500 bg-emerald-500/5' : 'border-slate-800'}`}>
                        <input id={`form-status-${status.value}`} type="radio" name="form-status" value={status.value}
                            checked={data.status === status.value} onChange={() => onChange('status', status.value)}
                            className="mt-0.5 size-4 accent-emerald-500" />
                        <span className="flex flex-col">
                            <span className="font-medium text-slate-100">{status.label}</span>
                            <span className="text-xs text-slate-400">{(isRegistration ? registrationStatusHelp : statusHelp)[status.value]}</span>
                        </span>
                    </label>
                ))}
                {errors.status && <p className="text-sm text-rose-400">{errors.status}</p>}
            </fieldset>

            <TextField label={isRegistration ? 'Name (only admins see this)' : 'Form title'} name="form-title" value={data.title} required maxLength={150}
                onChange={(value) => onChange('title', value)} error={errors.title} />

            {!isRegistration && (
                <>
                    <TextArea label="Introduction (optional)" name="form-description" value={data.description} maxLength={2000}
                        hint="Shown under the title, e.g. the date, venue and what to expect."
                        onChange={(value) => onChange('description', value)} error={errors.description} />

                    <TextField label="Web address" name="form-slug" value={data.slug} required maxLength={80}
                        hint={`${publicBase}${data.slug || '…'}`}
                        onChange={(value) => onChange('slug', value.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-{2,}/g, '-'))} error={errors.slug} />

                    <TextField label="Button text" name="form-submit-label" value={data.submit_label} maxLength={60} placeholder="Submit"
                        onChange={(value) => onChange('submit_label', value)} error={errors.submit_label} />

                    <TextArea label="Thank-you message" name="form-success-message" value={data.success_message} maxLength={1000}
                        placeholder="Thank you, we have received your response."
                        onChange={(value) => onChange('success_message', value)} error={errors.success_message} />

                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        <TextField label="Response limit" name="form-max-submissions" type="number" min={1} value={data.max_submissions}
                            hint="E.g. the number of seats. Leave empty for no limit."
                            onChange={(value) => onChange('max_submissions', value)} error={errors.max_submissions} />
                        <TextField label="Closes at" name="form-closes-at" type="datetime-local" value={data.closes_at}
                            hint="Leave empty to stay open."
                            onChange={(value) => onChange('closes_at', value)} error={errors.closes_at} />
                    </div>

                    <Checkbox label="Email a confirmation" name="form-sends-confirmation" checked={data.sends_confirmation}
                        description="Sent to the address in the first Email field, with your thank-you message. Wording is in Admin → Notifications."
                        onChange={(value) => onChange('sends_confirmation', value)} />
                    {errors.sends_confirmation && <p className="text-sm text-rose-400">{errors.sends_confirmation}</p>}
                </>
            )}

            {onDelete && (
                <div className="mt-2 border-t border-slate-800 pt-4">
                    <Button type="button" variant="danger" onClick={onDelete}>Delete form</Button>
                    <p className="mt-2 text-xs text-slate-400">Deletes the form and all of its responses.</p>
                </div>
            )}
        </div>
    );
}
