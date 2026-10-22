---
title: Send SMS with TelKo
summary: Get a TelKo SMS account and connect it so the site can send text messages.
order: 96
permission: manage_integrations
draft: true
---

The site sends text messages, such as sign-in codes, alerts and payment updates, through TelKo (TELKO-MS, Lagos), a bulk SMS provider. Until TelKo SMS is set up, text messages are skipped and only emails are sent.

## Get a TelKo account

You can't sign up for TelKo online. Their team opens your account and sends you the connection details.

1. Go to **Admin → Integrations** and choose **TelKo SMS (SMPP)**.
2. Under **Get an account**, use **Request a TelKo SMS account** to open TelKo's contact form. You can also email, call or chat with them on WhatsApp from the same panel.
3. Ask for an **SMPP account** for A2P bulk SMS. Also ask them to approve a **sender ID**, the name people see as the sender (up to 11 characters, for example your brand name).
4. TelKo sends you a host, a port, a system ID and a password. Keep the password private.

## Connect your account

1. Go to **Admin → Integrations** and choose **TelKo SMS (SMPP)**.
2. Enter the details from TelKo:
   - **Host** and **Port** (usually 2775)
   - **System ID** and **Password**
   - **System type**, only if TelKo gave you one
   - **Sender ID**, exactly as TelKo approved it
3. Choose a **Bind mode**. Use **transceiver** if TelKo sends delivery reports on the same connection; otherwise keep **transmitter**.
4. Leave **Default country code** blank for Nigeria (234). Change it only if most of your users have local numbers from another country.
5. Tick **Enable TelKo SMS (SMPP)** and choose **Save TelKo SMS (SMPP)**.
6. Choose **Test connection** to check that the site can sign in to TelKo.

## After it's connected

- Choose which events send a text message in **Admin → Notifications**.
- Text messages go to the phone number on each person's account. People without a phone number get email only.
- The password is stored securely and never shown again. To change it, enter a new one. Leave it blank to keep the current one.

## If something goes wrong

- **Test connection fails:** check the host, port, system ID and password against what TelKo sent you. TelKo may also need to allow your server's address on their side, so ask them.
- **Messages arrive from the wrong name, or don't arrive:** make sure the **Sender ID** matches the one TelKo approved.

You can also use any other SMS provider that offers an SMPP account. Enter their details on the same page.
