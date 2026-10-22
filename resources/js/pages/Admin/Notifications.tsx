import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import TextField from '../../components/ui/TextField';

type Channel = 'mail' | 'sms';

interface NotificationEventSettings {
    key: string;
    group: string;
    label: string;
    description: string;
    /** Channels the event can use. */
    available_channels: Channel[];
    /** Channels currently switched on. */
    channels: Channel[];
    required: boolean;
    has_action: boolean;
    placeholders: string[];
    customised: boolean;
    mail_subject: string;
    mail_body: string;
    sms_body: string | null;
}

interface NotificationsProps {
    events: NotificationEventSettings[];
    smsEnabled: boolean;
    smsMaxLength: number;
    smsSetupUrl: string;
}

export default function Notifications({ events, smsEnabled, smsMaxLength, smsSetupUrl }: NotificationsProps) {
    const eventsByGroup = events.reduce<Record<string, NotificationEventSettings[]>>((carry, event) => {
        if (!carry[event.group]) {
            carry[event.group] = [];
        }

        carry[event.group].push(event);

        return carry;
    }, {});
    const groups = Object.entries(eventsByGroup);

    return (
        <AdminLayout title="Notifications">
            <p className="text-sm text-slate-400">
                Choose how people hear about each event and what the messages say. Emails go through the mail provider in Integrations.
            </p>
            {!smsEnabled && (
                <p className="rounded-lg border border-amber-800 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
                    SMS is off, so SMS messages are skipped.{' '}
                    <Link href={smsSetupUrl} className="font-semibold underline">Set up TelKo SMS</Link>
                </p>
            )}
            {groups.map(([group, groupEvents]) => (
                <FunctionCategoryCard
                    key={group}
                    group={group}
                    events={groupEvents}
                    smsEnabled={smsEnabled}
                    smsMaxLength={smsMaxLength}
                />
            ))}
        </AdminLayout>
    );
}

function FunctionCategoryCard({
    group,
    events,
    smsEnabled,
    smsMaxLength,
}: {
    group: string;
    events: NotificationEventSettings[];
    smsEnabled: boolean;
    smsMaxLength: number;
}) {
    return (
        <details className="group rounded-2xl border border-slate-800 bg-slate-900/70">
            <summary className="flex cursor-pointer list-none items-center gap-3 px-5 py-4 focus-visible:outline-2 focus-visible:outline-emerald-400">
                <span className="flex-1">
                    <span className="block text-xs font-semibold uppercase tracking-wider text-slate-500">Function category</span>
                    <span className="block text-base font-semibold text-slate-50">{group}</span>
                </span>
                <span className="rounded-full bg-slate-800 px-2.5 py-0.5 text-xs text-slate-200">{events.length} notifications</span>
                <span aria-hidden="true" className="text-slate-500 transition group-open:rotate-180">▾</span>
            </summary>
            <div className="space-y-3 border-t border-slate-800 px-4 py-4">
                {events.map((event) => (
                    <EventPanel key={event.key} event={event} smsEnabled={smsEnabled} smsMaxLength={smsMaxLength} />
                ))}
            </div>
        </details>
    );
}

function EventPanel({ event, smsEnabled, smsMaxLength }: { event: NotificationEventSettings; smsEnabled: boolean; smsMaxLength: number }) {
    const supportsSms = event.available_channels.includes('sms');
    const url = `/admin/notifications/${event.key}`;
    const { data, setData, put, processing, errors, isDirty } = useForm({
        channels: event.channels,
        mail_subject: event.mail_subject,
        mail_body: event.mail_body,
        sms_body: event.sms_body ?? '',
    });

    function toggle(channel: Channel, on: boolean) {
        setData('channels', on ? [...data.channels, channel] : data.channels.filter((name) => name !== channel));
    }

    function submit(formEvent: FormEvent<HTMLFormElement>) {
        formEvent.preventDefault();
        put(url, { preserveScroll: true });
    }

    const channelBadges = event.channels.map((channel) => (channel === 'mail' ? 'Email' : 'SMS')).join(' + ') || 'Off';

    return (
        <details className="group rounded-2xl border border-slate-800 bg-slate-900/80">
            <summary className="flex cursor-pointer list-none flex-wrap items-center gap-3 px-5 py-4 focus-visible:outline-2 focus-visible:outline-emerald-400">
                <span className="flex-1">
                    <span className="block font-semibold text-slate-50">{event.label}</span>
                    <span className="block text-sm text-slate-400">{event.description}</span>
                </span>
                {event.customised && <span className="rounded-full border border-slate-700 px-2 py-0.5 text-xs text-slate-300">Customised</span>}
                <span className="rounded-full bg-slate-800 px-2.5 py-0.5 text-xs text-slate-200">{channelBadges}</span>
                <span aria-hidden="true" className="text-slate-500 transition group-open:rotate-180">▾</span>
            </summary>

            <form onSubmit={submit} className="flex flex-col gap-5 border-t border-slate-800 px-5 py-5">
                <fieldset className="flex flex-wrap gap-6">
                    <legend className="mb-2 text-sm font-medium text-slate-200">Send by</legend>
                    <Checkbox label="Email" name={`${event.key}-mail`} checked={data.channels.includes('mail')}
                        disabled={event.required} description={event.required ? 'Always sent: people need it to use their account.' : undefined}
                        onChange={(on) => toggle('mail', on)} />
                    {supportsSms && (
                        <Checkbox label="SMS" name={`${event.key}-sms`} checked={data.channels.includes('sms')}
                            description={smsEnabled ? 'Sent to the phone number on the account.' : 'Skipped until TelKo SMS is set up.'}
                            onChange={(on) => toggle('sms', on)} />
                    )}
                </fieldset>
                {errors.channels && <p role="alert" className="text-sm text-rose-400">{errors.channels}</p>}

                <div className="flex flex-col gap-1.5 text-sm">
                    <span className="font-medium text-slate-200">Placeholders</span>
                    <p className="flex flex-wrap gap-1.5">
                        {event.placeholders.map((placeholder) => (
                            <code key={placeholder} className="rounded bg-slate-800 px-1.5 py-0.5 text-xs text-emerald-300">{`{${placeholder}}`}</code>
                        ))}
                    </p>
                    {event.has_action && <p className="text-xs text-slate-400">The email also has a button that opens <code>{'{link}'}</code>.</p>}
                </div>

                <TextField label="Email subject" name={`${event.key}-mail-subject`} required maxLength={150}
                    value={data.mail_subject} onChange={(value) => setData('mail_subject', value)} error={errors.mail_subject} />
                <TextArea label="Email message" name={`${event.key}-mail-body`} rows={6} maxLength={5000}
                    hint="Leave a blank line between paragraphs. The greeting and sign-off are added for you."
                    value={data.mail_body} onChange={(value) => setData('mail_body', value)} error={errors.mail_body} />
                {supportsSms && (
                    <TextArea label="SMS message" name={`${event.key}-sms-body`} rows={3} maxLength={smsMaxLength}
                        hint={`${data.sms_body.length} characters. Over 160 characters is sent as several SMS.`}
                        value={data.sms_body} onChange={(value) => setData('sms_body', value)} error={errors.sms_body} />
                )}

                <div className="flex flex-wrap gap-3">
                    <Button disabled={processing}>Save</Button>
                    <Button type="button" variant="secondary" disabled={processing || isDirty}
                        title={isDirty ? 'Save your changes first' : undefined}
                        onClick={() => router.post(`${url}/test`, {}, { preserveScroll: true })}>
                        Send test to me
                    </Button>
                    {event.customised && (
                        <Button type="button" variant="secondary" disabled={processing}
                            onClick={() => window.confirm(`Reset "${event.label}" to the default channels and wording?`) && router.delete(url, { preserveScroll: true })}>
                            Reset to default
                        </Button>
                    )}
                </div>
            </form>
        </details>
    );
}

interface TextAreaProps {
    label: string;
    name: string;
    value: string;
    onChange: (value: string) => void;
    rows: number;
    maxLength: number;
    hint?: string;
    error?: string;
}

function TextArea({ label, name, value, onChange, rows, maxLength, hint, error }: TextAreaProps) {
    const describedBy = error ? `${name}-error` : hint ? `${name}-hint` : undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={name} className="text-sm font-medium text-slate-200">{label}</label>
            <textarea id={name} name={name} rows={rows} maxLength={maxLength} required value={value}
                onChange={(event) => onChange(event.target.value)} aria-invalid={error ? true : undefined} aria-describedby={describedBy}
                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none" />
            {error ? <p id={`${name}-error`} role="alert" className="text-sm text-rose-400">{error}</p>
                : hint && <p id={`${name}-hint`} className="text-xs text-slate-400">{hint}</p>}
        </div>
    );
}
