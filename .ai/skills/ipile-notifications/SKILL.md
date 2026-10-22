---
name: ipile-notifications
description: "Sends emails and SMS that users receive in an Ìpìlẹ̀ app through notification events whose wording and channels admins edit in the dashboard. Use when the developer wants to email, text, notify or alert a user or customer about something that happened, or asks to add a notification, reminder or alert."
---

# Ìpìlẹ̀ notifications

All email and SMS about things that happen in the app go through `App\Services\Notifications\Notify`. Admins choose channels and edit wording per event in Admin → Notifications. Full reference: `project-docs/dev-notes.md` → Notifications.

## Add an event

1. **Describe it** in `NotificationCatalog::all()` (`app/Services/Notifications/NotificationCatalog.php`): key, group, label, admin description, channels it can use, channels on by default, placeholders with example values, default email subject/body, default SMS text (or `null`), optional button label. Copy a neighbouring entry.
   - `{name}`, `{app_name}` and `{time}` exist for every event; do not list them.
   - Use `required: true` only when people cannot use their account without the email.
2. **Send it** where the thing happens:
   ```php
   Notify::event('report.ready')
       ->to($user)                       // or ->toEmail($address) / ->toPhone($number) for people without an account
       ->with(['link' => route('reports')])
       ->send();
   ```
   For Laravel or package events, add a listener in `app/Listeners` (discovered from the `handle()` type hint). For things that can repeat (webhook retries), send only when the state actually changes.
3. **Test it:**
   ```php
   Notification::fake();
   // …perform the action…
   Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $n) =>
       $n->event === 'report.ready');
   ```

## Scheduled or bulk sending (digests, reminders)

1. **Decide the recipients** with the developer if the request is vague (team owner? team admins? everyone with a permission?). Express it as a query, e.g. `$team->users()->wherePivot('role', 'admin')` plus `$team->owner`, or `User::permission('view_billing')` for a global permission.
2. **A queued job** (`php artisan make:job SendWeeklySummaries`, `implements ShouldQueue`) that loops over teams/recipients and calls `Notify` for each. `Notify` itself sends synchronously, so queue the loop, not the notification.
3. **Schedule it** in `routes/console.php` next to the existing entries: `Schedule::job(new SendWeeklySummaries)->weeklyOn(1, '08:00');`. Production needs a cron running `php artisan schedule:run` every minute and a queue worker.
4. **Guard it:** check the admin switch (a `Setting`) and any per-team choice inside the job; make it safe to run twice (e.g. record `last_sent_at` and skip if already sent for the period).
5. **Test** the job directly with `Notification::fake()`: sent to the right people, skipped when switched off, not sent twice.

## Good to know

- Email uses the `dashboard` mailer (ZeptoMail when enabled in Integrations, otherwise the fallback mailer). SMS needs TelKo SMS (SMPP) enabled and the user's `phone_number`.
- Sending failures are logged and never break the action that triggered them.
- A one-off SMS that is not a user-facing event: `app(SmsSender::class)->queue($to, $text, $user)`.

## If the developer wants something different

A plain Laravel `Mailable` or `Notification` is fine for messages admins will never need to reword (say once that it will not appear in Admin → Notifications). Keep personal data out of logs either way.
