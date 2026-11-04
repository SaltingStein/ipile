---
title: Choose how the site sends email
summary: Connect an email provider, such as Resend's free plan or ZeptoMail, and choose which one sends the site's email.
order: 95
permission: manage_integrations
draft: true
---

The site sends email, such as sign-up confirmations, password resets, invitations and notifications, through an email provider you connect. You can connect more than one and choose which one is used.

## Set up Resend (free plan)

Resend's free plan covers 3,000 emails a month and up to 100 a day.

1. Go to **Admin → Integrations** and choose **Resend**.
2. Under **Get an account**, use **Create a free Resend account**.
3. In Resend, add your domain and follow its steps to verify it (**Verify your domain**). Email can only be sent from a verified domain.
4. In Resend, create an API key. A key with **Sending access** is enough. Copy it; it starts with `re_`.
5. Back on the **Resend** page, enter:
   - **API key**
   - **From address**, an address on your verified domain, for example noreply@yourdomain.com
   - **From name**, the name people see as the sender
6. Tick **Enable Resend** and choose **Save Resend**.
7. Choose **Test connection**. A short test email is sent to the From address.

## Choose which provider sends email

When more than one email provider is connected:

1. Go to **Admin → Integrations**.
2. Under **Email delivery**, pick a provider in **Send email through**.
3. Choose **Save**.

**Automatic (the first one switched on)** uses the first provider in the list that is switched on. If the provider you choose is switched off, another switched-on provider is used until you switch yours back on. The line under the choice shows which provider is sending email now.

## If something goes wrong

- **The test fails with "domain is not verified":** finish verifying your domain in Resend, or use a From address on a verified domain.
- **No email arrives:** check that the provider is switched on and is the one shown under **Send email through**. On Resend's free plan, email stops for the day after 100 messages.
- The API key is stored securely and never shown again. To change it, enter a new one. Leave it blank to keep the current one.
