import type { ButtonHTMLAttributes } from 'react';

/**
 * Shared button styles. `primary` for the main action, `secondary` for alternatives, `danger` for destructive actions.
 */
interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: 'primary' | 'secondary' | 'danger';
}

const variantClasses: Record<NonNullable<ButtonProps['variant']>, string> = {
    primary: 'bg-emerald-500 text-slate-950 hover:bg-emerald-400',
    secondary: 'border border-slate-700 text-slate-100 hover:border-slate-500',
    danger: 'bg-rose-600 text-white hover:bg-rose-500',
};

export default function Button({ variant = 'primary', type = 'submit', className = '', ...buttonProps }: ButtonProps) {
    return (
        <button
            type={type}
            className={`inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition disabled:opacity-60 ${variantClasses[variant]} ${className}`}
            {...buttonProps}
        />
    );
}
