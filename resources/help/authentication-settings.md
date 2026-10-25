---
title: Manage sign-in and two-factor rules
summary: Review available sign-in methods, control new accounts and guest access, and set two-factor and API token rules.
order: 97
permission: manage_settings
draft: true
---

Open **Admin → Security → Sign-in & 2FA**. These settings apply across the site. Managing them needs permission to manage settings.

## Review available sign-in methods

**Available sign-in methods** shows the saved methods people can use. Email and password is always available. A connected provider, such as Google, appears only when it is enabled and both its client ID and client secret are saved.

When enabled, **Email sign-in link** also appears in the summary. The summary shows whether new registrations and guest access are on. Unsaved changes do not appear in the summary until you save them.

## Control new accounts and guest access

1. Under **Sign-in rules**, set **Allow new registrations**. Turning it off closes the registration page, registration through apps and new accounts through connected providers. Existing accounts can still sign in.
2. Set **Allow guest access**. Guest access lets visitors try the site with just a nickname, without creating an account. It is off unless you turn it on (sites that already had guest accounts keep it on). While it is off, the home page offers **Create account** and **Sign in** instead, and no new guest accounts can be started. Existing guests can keep using their account and can still create a full account. This is separate from registration, so close both if you want to prevent both kinds of new account.
3. Choose **Save sign-in rules**.

### Word the guest button your way

While **Allow guest access** is ticked, you can fill in **Guest button wording**, for example "Try the demo", and **Guest name field wording**, the hint shown in the empty name field. Each can be up to 40 characters. Leave them blank to use the home page design's own wording, then choose **Save sign-in rules**.

### Delete unused guest accounts

Set **Delete unused guest accounts after (days)** to remove guest accounts nobody has used for that many days. Enter **0** to keep them (the default), or at least **7**. Deleted guest accounts cannot be recovered, and full accounts are never affected. Clean-up runs once a night, so your server must run scheduled tasks.

## Allow email sign-in links

1. Under **Sign-in rules**, tick **Allow sign-in by email link**. It is off until you enable it.
2. Set **Sign-in link expiry (minutes)**, from **1 to 60**. The default is **15**. This changes new links; existing links keep their expiry.
3. Choose **Save sign-in rules**. The sign-in page now offers **Email me a sign-in link**.
4. Confirm your email delivery service is configured in **Admin → Integrations → Email delivery**, then test using an account you control. If delivery is delayed, ask your server administrator to check queued email delivery.

Links work once, and requesting a new one replaces earlier links. Only existing active full accounts receive them, including accounts that have no password. Two-factor sign-in still applies. Closing registration does not turn this method off; clear **Allow sign-in by email link** to stop requesting and using links.

The **Email sign-in link** event in **Admin → Notifications → Account** controls the email wording. Its email channel cannot be switched off, because the link is needed to sign in. Keep the expiry and safety advice in the message. Managing notifications requires its own permission.

Requests are limited to three per hour for each email and network, with an additional short limit for a shared network. The confirmation does not reveal whether an email has an account. See [Sign in with an email link](/help/magic-link-login) for the user steps.

## Require two-factor authentication

1. Under **Roles that must use two-factor authentication**, tick the roles whose members must set up an authenticator app.
2. Set **Trusted device period (days)** to control how long trusting a device skips the two-factor challenge. Use **0** to remove the trust option.
3. Choose **Save sign-in rules**.

Two-factor rules still apply when a person signs in through a connected provider. See [Account security](/help/account-security) for user-facing security options.

## Limit API tokens

- **API token lifetime (days)** sets the expiry of newly created tokens. Existing tokens keep their saved expiry. Use **0** for no lifetime limit.
- **API token idle timeout (days)** stops a token working after that many unused days. This also applies to existing tokens. Use **0** to turn the idle timeout off.

Choose **Save sign-in rules** after changing these values. A token that no longer works must be replaced through the usual sign-in or token-creation flow.

## Configure a connected provider

1. Create a sign-in client in the provider's developer console.
2. Copy **Authorised redirect URI (copy into the provider console)** exactly into that console.
3. Enter **Client ID** and **Client secret** in the provider's panel.
4. Tick the provider's **Show "Continue with …" on the sign-in and register pages** checkbox, then choose its **Save settings** button.
5. Check **Available sign-in methods** and test the sign-in flow using an account you control.

Client secrets are stored encrypted and never shown again. Leave the secret blank on a later save to keep the saved value. Only providers supported by this build are listed.

Use the provider's **Test connection** button after saving to check its consent flow, including while the provider is switched off. The test does not create or connect an account.

Select **Require profile completion for connected sign-up** under **Sign-in rules** to ask new connected-account users to confirm their name and answer the published sign-up questions before creating an account. Clear it for automatic registration. Choose **Save sign-in rules** after changing it.

See [Set up connected sign-in providers](/help/oauth-providers) for Google, GitHub, LinkedIn and X setup and troubleshooting.
