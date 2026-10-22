<?php

namespace App\Services\Notifications;

use InvalidArgumentException;

/**
 * Every event the app can notify people about.
 *
 * Adding an event is a code change (a definition here plus a `Notify::event()` call where it happens);
 * choosing its channels and wording never is.
 */
class NotificationCatalog
{
    /**
     * @return array<string, NotificationEvent> keyed by event key
     */
    public function all(): array
    {
        $mail = [NotificationEvent::MAIL];
        $mailAndSms = [NotificationEvent::MAIL, NotificationEvent::SMS];
        $time = ['time' => '15 Oct 2026, 09:30 WAT'];

        $events = [
            new NotificationEvent('auth.welcome', 'Account', 'Welcome',
                'Sent when someone creates an account (email or social sign-up).',
                $mailAndSms, $mail, ['link' => url('/dashboard')],
                'Welcome to {app_name}',
                "Thanks for creating your {app_name} account. You're all set.",
                'Welcome to {app_name}, {name}! Your account is ready.',
                'Open your dashboard'),
            new NotificationEvent('auth.verify_email', 'Account', 'Verify email address',
                'Sent after sign-up and when someone asks for a new verification link.',
                $mail, $mail, ['link' => url('/email/verify/1/example'), 'expires_in' => '60'],
                'Verify your email address',
                "Please confirm this is your email address.\n\nThe link expires in {expires_in} minutes. If you did not create an account, you can ignore this email.",
                actionLabel: 'Verify email address', required: true),
            new NotificationEvent('auth.magic_link', 'Account', 'Email sign-in link',
                'Sent when someone requests a passwordless sign-in link. Only existing active full accounts receive it.',
                $mail, $mail, ['link' => url('/auth/magic-link/example'), 'expires_in' => '15'],
                'Your sign-in link for {app_name}',
                "Use this link to sign in to your {app_name} account.\n\nIt works once and expires in {expires_in} minutes. Requesting another link replaces it. Do not share it. If you did not request this email, ignore it.",
                actionLabel: 'Sign in', required: true),
            new NotificationEvent('auth.password_reset', 'Account', 'Password reset link',
                'Sent when someone asks to reset a forgotten password.',
                $mail, $mail, ['link' => url('/reset-password/example'), 'expires_in' => '60'],
                'Reset your password',
                "We received a request to reset the password for your {app_name} account.\n\nThe link expires in {expires_in} minutes. If you did not ask for this, you can ignore this email.",
                actionLabel: 'Reset password', required: true),

            new NotificationEvent('security.password_changed', 'Security', 'Password changed',
                'Sent after a password is changed or reset. Other devices are signed out at the same time.',
                $mailAndSms, $mail, [...$time, 'link' => url('/forgot-password')],
                'Your password was changed',
                "The password for your {app_name} account was changed on {time}.\n\nIf this wasn't you, reset your password straight away.",
                '{app_name}: your password was changed on {time}. Not you? Reset it now.',
                'Reset password'),
            new NotificationEvent('security.two_factor_enabled', 'Security', 'Two-factor turned on',
                'Sent when an authenticator app is set up on the account.',
                $mailAndSms, $mail, [...$time, 'link' => url('/settings/security')],
                'Two-factor authentication is on',
                "Two-factor authentication was turned on for your {app_name} account on {time}.\n\nIf this wasn't you, contact us straight away.",
                '{app_name}: two-factor authentication was turned on ({time}).',
                'Review account security'),
            new NotificationEvent('security.two_factor_disabled', 'Security', 'Two-factor turned off',
                'Sent when the authenticator app is removed from the account.',
                $mailAndSms, $mail, [...$time, 'link' => url('/settings/security')],
                'Two-factor authentication is off',
                "Two-factor authentication was turned off for your {app_name} account on {time}.\n\nIf this wasn't you, change your password and turn it back on.",
                '{app_name}: two-factor authentication was turned off ({time}). Not you? Secure your account now.',
                'Review account security'),
            new NotificationEvent('security.new_sign_in', 'Security', 'New sign-in',
                'Sent when someone signs in from a browser that has not been used with the account before.',
                $mailAndSms, $mail, [...$time, 'ip_address' => '102.89.0.1', 'device' => 'Chrome on Windows', 'link' => url('/settings/security')],
                'New sign-in to your account',
                "Your {app_name} account was signed in to from a new device.\n\nDevice: {device}\nIP address: {ip_address}\nTime: {time}\n\nIf this was you, there's nothing to do. If not, change your password and sign out other sessions.",
                '{app_name}: new sign-in from {device} ({time}). Not you? Change your password.',
                'Review account security'),

            new NotificationEvent('security.suspicious_activity', 'Security', 'Suspicious activity',
                'Sent by Aegis (Admin → Security) when an account action looks risky enough to alert the owner. At most once an hour per account.',
                $mailAndSms, $mailAndSms, [...$time, 'activity' => 'Two-factor turned off', 'ip_address' => '102.89.0.1', 'device' => 'Chrome on Windows', 'link' => url('/settings/security')],
                'Unusual activity on your account',
                "We noticed unusual activity on your {app_name} account and took steps to protect it.

Activity: {activity}
Device: {device}
IP address: {ip_address}
Time: {time}

If this was you, there's nothing to do. If not, change your password and sign out other sessions straight away.",
                '{app_name}: unusual activity on your account ({activity}, {time}). Not you? Change your password now.',
                'Review account security'),

            new NotificationEvent('security.admin_alert', 'Security', 'High-risk activity (to administrators)',
                'Sent to everyone who can see threat monitoring (and super admins) when someone asks the site assistant for passwords, codes, keys or other people’s data. Always sent by email, so administrators always hear about it.',
                $mail, $mail, [...$time, 'activity' => 'Asked the site assistant for another user’s password', 'person' => 'Ada Obi (ada@example.com)', 'action' => 'Signed out everywhere; two-factor needed to sign in again', 'ip_address' => '102.89.0.1', 'link' => url('/admin/security')],
                'High-risk activity: {activity}',
                'Aegis recorded high-risk activity on {app_name}.

What happened: {activity}
Who: {person}
What was done: {action}
IP address: {ip_address}
Time: {time}

Review the incident and decide whether to restore the account or take further action.',
                actionLabel: 'Review the incident', required: true),

            new NotificationEvent('team.invitation', 'Teams', 'Team invitation',
                'Sent to an email address invited to join a team.',
                $mail, $mail, ['team' => 'Acme', 'inviter' => 'Tunde', 'link' => url('/team-invitations/example'), 'expires_in_days' => '7'],
                'You have been invited to join {team}',
                "{inviter} invited you to join the {team} team on {app_name}.\n\nIf you do not have an account yet, create one with this email address, then open the link again.\n\nThis link expires in {expires_in_days} days. If you were not expecting this invitation, you can ignore this email.",
                actionLabel: 'Accept invitation', required: true),

            new NotificationEvent('forms.submission_received', 'Forms', 'Form response received',
                'Sent to the address in a form\'s Email field when the form has "Email a confirmation" switched on.',
                $mail, $mail, ['form' => 'Lagos Tech Summit registration', 'message' => 'Thank you, your place is reserved. See you there!'],
                'We received your response: {form}',
                "Thanks for filling in {form}.\n\n{message}\n\nIf this was not you, you can ignore this email."),

            new NotificationEvent('payment.succeeded', 'Payments', 'Payment received',
                'Sent when a payment is confirmed by the payment provider.',
                $mailAndSms, $mail, ['amount' => 'NGN 5,000.00', 'reference' => 'PAY-12345'],
                'Payment received: {amount}',
                "We received your payment of {amount}.\n\nReference: {reference}",
                '{app_name}: payment of {amount} received. Ref {reference}.'),
            new NotificationEvent('payment.failed', 'Payments', 'Payment failed',
                'Sent when the payment provider reports a payment as failed.',
                $mailAndSms, $mail, ['amount' => 'NGN 5,000.00', 'reference' => 'PAY-12345'],
                'Payment failed: {amount}',
                "Your payment of {amount} did not go through, and you have not been charged.\n\nReference: {reference}",
                '{app_name}: payment of {amount} failed. Ref {reference}.'),
        ];

        return collect($events)->keyBy('key')->all();
    }

    /**
     * @throws InvalidArgumentException for an unknown key, so a typo fails loudly in development.
     */
    public function find(string $key): NotificationEvent
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown notification event [{$key}]. Add it to ".self::class.'.');
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }
}
