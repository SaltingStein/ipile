import type { FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';

/**
 * Collects a nickname and starts a guest session via the guest onboarding route.
 * Shown only to visitors who are not signed in, and only while guest access is on (Admin → Sign-in & 2FA), which also
 * holds optional wording that replaces the layout's button label and the field placeholder.
 * Styling is passed in so each landing layout can keep its own visual theme.
 */
interface GuestStartFormProps {
    buttonLabel: string;
    inputClassName: string;
    buttonClassName: string;
    errorClassName: string;
}

interface GuestStartWording {
    guestStart?: { buttonLabel: string | null; fieldLabel: string | null };
}

export default function GuestStartForm({ buttonLabel, inputClassName, buttonClassName, errorClassName }: GuestStartFormProps) {
    const { auth, canStartGuest, guestStart } = usePage<SharedPageProps & GuestStartWording>().props;
    const { data, setData, post, processing, errors } = useForm({
        nickname: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/guest/start');
    }

    if (!canStartGuest || auth.user) {
        return null;
    }

    const fieldLabel = guestStart?.fieldLabel ?? 'Pick a nickname';

    return (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <div className="flex flex-wrap gap-3">
                <input
                    type="text"
                    name="nickname"
                    aria-label={fieldLabel}
                    placeholder={fieldLabel}
                    minLength={2}
                    maxLength={20}
                    required
                    value={data.nickname}
                    onChange={(event) => setData('nickname', event.target.value)}
                    className={inputClassName}
                />
                <button type="submit" disabled={processing} className={`${buttonClassName} disabled:opacity-60`}>
                    {guestStart?.buttonLabel ?? buttonLabel}
                </button>
            </div>
            {errors.nickname && <p className={errorClassName}>{errors.nickname}</p>}
        </form>
    );
}
