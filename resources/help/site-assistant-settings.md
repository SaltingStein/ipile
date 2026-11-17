---
title: Set up the site assistant
summary: Choose the built-in assistant, a third-party chat widget or no assistant, and manage the built-in assistant's protections.
order: 99
permission: manage_settings
draft: true
---

Choose what powers the assistant in **Admin → Platform → Site assistant**, under **Assistant source**. Choose **Built-in assistant**, **Third-party widget** or **Off**, then **Save assistant**. Only the chosen source is available. Guest accounts do not get access.

The built-in assistant answers from the Help Center, from each person's own account and teams, and from the business figures you allow. Its settings are kept when you choose another source.

## Use a third-party widget

1. Ask the widget administrator for its HTTPS address, integration ID and access mode. They must allow this site's exact address to embed the widget.
2. In **Admin → Integrations → Assistant widgets → Third-party assistant widget**, enter **Widget address**, **Integration ID** and **Access mode**. This needs permission to manage integrations.
3. For **signed** access, enter the **Shared secret** and **Role mapping** supplied for your site. Match global site roles to the service's roles, for example `{"admin":"staff","member":"customer"}`. The first matching entry wins. Unmapped accounts get no access, including administrators unless their role is mapped.
4. Set **Claim lifetime (seconds)** if needed. It defaults to 300 seconds and must be between 1 and 300, no longer than the service allows. The shared secret stays encrypted and is never sent to the browser. Leaving it blank on later saves keeps the saved value.
5. Tick **Enable Third-party assistant widget** and choose **Save Third-party assistant widget**.
6. In **Site assistant**, choose **Third-party widget** and **Save assistant**. Open a workspace page with a full account to use the chat bubble.

With **static** access, everyone gets the same access profile. No shared secret or role mapping is needed. Use it only for data that all full accounts may see.

The external service handles its own data, conversations and access rules. Ìpìlẹ̀'s built-in guard rails, message limits and account lock-out rules do not cover it, and it only reads this site's data if you switch on data access (below). Leaving the workspace, signing out or changing the widget configuration refreshes the page to close its chat session.

If no widget appears, check that the integration is enabled, the account's role is mapped for signed access, and the service allows your site's address. A person who can manage settings but cannot manage integrations must ask an integrations manager to configure it.

## Let the widget read site data

The widget can also answer from this site's own data for the person using it: help articles they can see, their own roles and permissions, and the business figures you allow under **Business figures it may read**. It never gets names, email addresses, passwords, codes, keys or anyone else's details. This is off unless you switch it on, and it needs **signed** access.

1. Ask the widget administrator for the IP addresses their service connects from.
2. In **Admin → Integrations → Assistant widgets → Third-party assistant widget**, set **Data access** to **on**.
3. Under **Data access networks**, enter those addresses or ranges, separated by commas, for example `203.0.113.10, 198.51.100.0/24`. Requests from anywhere else are refused.
4. Choose **Save Third-party assistant widget**.
5. Give the widget administrator your site's data address: your site's address followed by `/mcp/widgets`.

Each person's access lasts only as long as their claim (the **Claim lifetime**), and ends at once when they are signed out everywhere, change their password, lose their mapped role or are suspended. Every read and every refused attempt is recorded in **Admin → Security** under **Assistant widget data**.

## Switch on the built-in assistant

1. Switch on an AI model first in **Admin → Integrations → AI models**.
2. In **Admin → Platform → Site assistant**, choose **Built-in assistant**, tick **Assistant is on** and choose the **AI model**.
3. Set **Messages per person per day**.
4. Leave **Answer from the Help Center** ticked so it can search your help articles to explain how things work. Untick it and the assistant points people to the Help Center instead.
5. Optionally, describe your business under **About your business**: what you offer and who your customers are, in up to 1,500 characters. The assistant uses it as background, and it never overrides the assistant's rules. Do not include passwords, keys or personal details, because anyone using the assistant may be told what you write.
6. Under **Business figures it may read**, tick the figures it may report, such as accounts, campaign results or payments. Figures your own app adds appear in the same list and work the same way. A person only gets a figure if you tick it here **and** they have the permission shown next to it, so nobody learns more than their own admin pages show them.
7. Under **When someone asks for protected data**, choose what happens to their account (see below).
8. Choose **Save assistant**.

## Choose who can use the assistant

Using the assistant needs the `use_ai_assistant` permission. Every full account gets the **member** role, which has it, and so do the **admin** and **marketing** roles, so everyone with a full account can use the assistant unless you change this. Guest accounts never can.

To limit it, open **Admin → Security → Roles & permissions** and remove `use_ai_assistant` from the **member** role. Then give it only to the roles that should keep it. People who lose the permission no longer see the assistant, whether it is the built-in one or a third-party widget.

## Review assistant activity

Every request to the built-in assistant is recorded, whatever happened to it. Open **Admin → Platform → Assistant activity**; this needs the `view_ai_activity` permission, which the **admin** role has.

1. Filter by person, by a role they had at the time, by mode, by outcome or by date, then choose **Apply filters**.
2. Choose a request's time to open it. You see the question and the answer, the roles, team and modes the person had **when they asked** (later changes do not alter the record), the tools the assistant used with shortened, masked arguments, and how long it took.

Outcomes are **Answered**, **Beyond their access** (a staff question from someone without that staff mode, answered by the normal assistant instead), **Over the daily limit**, **Refused (protected data)** and **AI service error**. Refused requests keep no message here; the incident in **Admin → Security → Incidents** shows what was asked. Questions beyond someone's access and repeated messages past the daily limit are also recorded in threat monitoring.

## Choose how long chats and activity are kept

People's chats and the activity log are kept for **90 days** unless you change it. Under **Chats and activity log**, set **Keep chats and the activity log for (days)** to between 7 and 730, then choose **Save assistant**. Older chats and log entries are deleted every night, so your server must run scheduled tasks.

## Switch on staff modes

Staff modes let people who run the site use the same assistant for site guidance and analytics. Everyone else keeps the normal assistant. Each message is routed automatically, and the answer shows which mode it came from.

- **Site guidance** answers questions about running and extending the site from the internal guides (the developer guide, technical notes and extension guides that come with the site) as well as the Help Center, including admin articles the person can see. It needs the `use_assistant_guidance` permission.
- **Analytics** summarises and compares the business figures you ticked above that the person may see. It needs the `use_assistant_analytics` permission.

1. In **Admin → Platform → Site assistant**, under **Staff modes**, tick the modes to offer and choose **Save assistant**.
2. In **Admin → Security → Roles & permissions**, give the permissions to the roles that should use them. The **admin** role has both by default.

Staff modes are offered in the built-in assistant (its page and its chat bubble), never through a third-party widget. Screening, the daily message limit and the lock-out rules below apply in every mode: staff who ask for passwords, codes, keys, other people's data or protected system data are locked out like anyone else. Every request is recorded in **Assistant activity** (below).

## Allow analytics insights

1. Switch on the built-in assistant and the **Analytics** staff mode.
2. Under **Analytics insights**, select the summaries staff should be able to ask about, then choose **Save assistant**. Nothing is selected by default.
3. Give staff the Analytics permission and the additional permission shown beside each insight. A team insight also needs membership of the current team and the team role required for that data.

Built-in insights cover sign-ups over time, recently active accounts, new teams, payment breakdowns, SMS delivery counts and security incident counts. Results cover the last 30 days and at most 50 groups. They contain counts and totals rather than individual records.

SMS and security counts depend on the site's scheduled refresh. If a summary has not been refreshed or is more than 15 minutes old, the assistant says it is unavailable. Ask the site operator to check scheduled work. The assistant still cannot read SMS bodies, recipients or raw security logs.

Changing team changes the data available to a team insight. Removing a permission, unticking an insight or switching the assistant off takes effect on subsequent tool calls. These tools are only available in the built-in staff Analytics mode.

## Choose what happens to someone who asks for protected data

Either way, the request is refused, they are signed out on every device, the case is logged as a high-risk incident and administrators get an email. This applies to everyone, administrators included.

- **Suspend the account until an administrator restores it** (the default): they cannot sign in at all until someone restores the account from the incident.
- **Sign them out and require their authenticator code**: they can sign back in with their password and a code from their authenticator app. Accounts without two-factor are still suspended.

If every super admin ends up suspended, nobody can restore accounts from the dashboard. Someone with access to the server can run `php artisan ipile:restore-user email@example.com` to let a person sign in again.

## Built-in guard rails (always on)

These protect the built-in assistant and cannot be switched off. They do not cover third-party widgets:

- **Screening:** every message is checked before the assistant sees it. Asking for passwords, sign-in or recovery codes, two-factor secrets, API or payment keys, other people's details, or protected system data is refused. Questions about *how* to reset a password or add keys are fine.
- **Lock-out:** whoever asks, administrators included, is signed out on every device at once, then suspended or asked for their authenticator code, as you chose above.
- **Alerts:** each case is logged as a high-risk incident in **Admin → Security → Incidents**, even while monitoring is off. Everyone who can see threat monitoring gets a **High-risk activity** email.
- **Data block:** the assistant cannot read integration settings (such as payment gateway keys), passwords, codes, sessions, tokens or form answers, however the question is phrased. The full list, including anything your own app has added, is under **Data the assistant can never read**.
- **Answer check:** anything in an answer that looks like a key, token or code is removed before it is shown.

## Restore a suspended account

1. Open the email, or find the incident in **Admin → Security → Incidents**.
2. Read what was asked under the incident's events.
3. If you are satisfied the account is safe, choose **Restore account**. The person can sign in again straight away.
4. Resolve the incident as **Benign**, **False positive** or **Confirmed attack**.

Restoring an account needs the `suspend_users` permission, which the **admin** role has.
