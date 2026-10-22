import type { FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';

/**
 * Collects a nickname and starts a guest session via the guest onboarding route.
 * Styling is passed in so each landing layout can keep its own visual theme.
 */
interface GuestStartFormProps {
    buttonLabel: string;
    inputClassName: string;
    buttonClassName: string;
    errorClassName: string;
}

export default function GuestStartForm({ buttonLabel, inputClassName, buttonClassName, errorClassName }: GuestStartFormProps) {
    const { canStartGuest } = usePage<SharedPageProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        nickname: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/guest/start');
    }

    if (!canStartGuest) {
        return null;
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <div className="flex flex-wrap gap-3">
                <input
                    type="text"
                    name="nickname"
                    aria-label="Nickname"
                    placeholder="Pick a nickname"
                    minLength={2}
                    maxLength={20}
                    required
                    value={data.nickname}
                    onChange={(event) => setData('nickname', event.target.value)}
                    className={inputClassName}
                />
                <button type="submit" disabled={processing} className={`${buttonClassName} disabled:opacity-60`}>
                    {buttonLabel}
                </button>
            </div>
            {errors.nickname && <p className={errorClassName}>{errors.nickname}</p>}
        </form>
    );
}
