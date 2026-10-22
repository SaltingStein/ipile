---
title: Set up connected sign-in providers
summary: Configure connected sign-in providers and check account linking.
order: 98
permission: manage_settings
draft: true
---

Connected providers let people sign in without creating a separate password. This build supports Google, GitHub, LinkedIn and X. Open **Admin → Security → Sign-in & 2FA** to configure them. These settings apply across the site and require permission to manage settings.

## Set up Google

1. In Google Cloud, select your project and configure its Google Auth Platform branding and audience. Add test users if the app is in testing.
2. Under **Clients**, create an OAuth client with application type **Web application**.
3. Copy **Authorised redirect URI** from the Google panel in Ìpìlẹ̀ into the client's **Authorized redirect URIs**.
4. Save the client and enter its **Client ID** and **Client secret** in Ìpìlẹ̀.
5. Tick **Show "Continue with Google" on the sign-in and register pages**, then choose **Save Google settings**.

See [Google's OAuth client setup guide](https://developers.google.com/identity/protocols/oauth2/web-server) for current console steps and publication requirements.

## Set up GitHub

1. In GitHub, open **Settings → Developer settings → OAuth Apps → New OAuth App**.
2. Enter an application name and your site's homepage URL.
3. Copy **Authorised redirect URI** from the GitHub panel in Ìpìlẹ̀ into GitHub's **Authorization callback URL**.
4. Register the application, generate a client secret, and enter its **Client ID** and **Client secret** in Ìpìlẹ̀.
5. Tick **Show "Continue with GitHub" on the sign-in and register pages**, then choose **Save GitHub settings**.

Use a separate OAuth App for each environment with a different callback URL. See [GitHub's OAuth App setup guide](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app).

GitHub sign-in requests read access to email addresses. A new account needs a verified primary email; it can be private on GitHub. Repository access is not requested.

## Set up LinkedIn

1. Create or select your app in the LinkedIn Developer Portal.
2. Under **Products**, request **Sign In with LinkedIn using OpenID Connect**. Wait until the app has access to its sign-in permissions.
3. Under **Auth**, add the URI from Ìpìlẹ̀'s LinkedIn panel to the app's authorized redirect URLs.
4. Enter the app's **Client ID** and **Client secret** in that panel.
5. Tick **Show "Continue with LinkedIn" on the sign-in and register pages**, then choose **Save LinkedIn settings**.

The app requests profile and email access. LinkedIn may omit email information; a new account needs an email address. An address is marked verified only when LinkedIn confirms verification. See [LinkedIn's sign-in setup guide](https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2).

## Set up X

1. Create or select an app in the X Developer Console and enable **OAuth 2.0** in its user authentication settings.
2. Choose **Web App**, which supplies a client secret, and register the callback URI shown in Ìpìlẹ̀'s X panel exactly.
3. Allow email access for the app if the console offers this option. Ensure your app's API access permits retrieving the signed-in user's email.
4. Enter the **OAuth 2.0 Client ID** and **Client secret** in Ìpìlẹ̀. These differ from OAuth 1.0 API keys and an app-only bearer token.
5. Tick **Show "Continue with X" on the sign-in and register pages**, then choose **Save X settings**.

Sign-in requests profile, email and post-read permissions required by the installed provider. It does not request posting or private-message access. A new account needs the confirmed email returned by X; a public verification badge does not verify an email address. See [X's OAuth 2.0 setup guide](https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code).

## Check sign-in

1. Confirm the provider appears under **Available sign-in methods** after saving.
2. Open a separate browser session where you are signed out of Ìpìlẹ̀, choose the provider on the sign-in page, and finish its consent flow using an account you control.
3. If this is a new account, registration must be open. Existing linked accounts can still sign in when registration is closed.
4. Confirm any required two-factor challenge appears. Connected sign-in follows the site's usual two-factor and suspension rules.

Saving credentials does not check them with the provider. Completing sign-in checks the client, secret, callback URL and consent settings together.

## Connect an existing account

If the provider's email already belongs to an Ìpìlẹ̀ account, sign in to that existing account first. Open **Account security** and connect the provider there. Matching email addresses never automatically merge accounts. A signed-in guest who creates a new account through a provider keeps the same account.

## Update or turn off a provider

Client secrets are encrypted and never displayed after saving. Leave **Client secret** blank to keep its saved value. To rotate it, generate a replacement at the provider, paste it here and save, then test sign-in before retiring the old secret.

To disable sign-in, clear the provider's checkbox and save. This removes its sign-in button and prevents new sign-in attempts through that provider. People who rely on it need another sign-in method available.

## Troubleshooting

- **The button is missing:** save the enable checkbox, client ID and client secret. All three are needed.
- **Callback or redirect mismatch:** copy the URI shown for the current site exactly into the provider console, including scheme, hostname and path.
- **Sign-in was cancelled or failed:** restart from the sign-in button in the same browser and allow cookies. Check the saved credentials and provider audience or test-user restrictions if it keeps failing.
- **GitHub did not share an email:** verify your primary email in GitHub and authorize the email permission again.
- **LinkedIn sign-in is unavailable:** check that the app has the OpenID Connect sign-in product and its profile and email permissions.
- **X did not share an email:** check app email permissions and your X account's confirmed email. To connect an identity that has no shared email, sign in to an existing Ìpìlẹ̀ account first and connect it from Account security.
- **An account with this email already exists:** sign in to that account and connect the provider from Account security.
- **Registration is closed:** an administrator must reopen registration before a new provider identity can create an account.
