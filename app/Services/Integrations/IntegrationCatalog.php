<?php

namespace App\Services\Integrations;

use App\Services\Ai\AnthropicApi;
use App\Services\Ai\Testers\GeminiTester;
use App\Services\Ai\Testers\OllamaTester;
use App\Services\Ai\Testers\OpenAiCompatibleTester;
use App\Services\Ai\Testers\OpenAiTester;
use App\Services\Ai\Testers\OpenRouterTester;
use App\Services\Assistant\AssistantWidget;
use App\Services\Mail\ZeptoMailTester;
use App\Services\Media\BucketStorageTester;
use App\Services\Media\MediaStorage;
use App\Services\Payments\Flutterwave\FlutterwaveTester;
use App\Services\Payments\Opay\OpayTester;
use App\Services\Payments\Paystack\PaystackTester;
use App\Services\Payments\Squad\SquadTester;
use App\Services\Security\Analysis\AnthropicTester;
use App\Services\Sms\SmppTester;

/**
 * Every provider that can be configured from Admin → Integrations.
 *
 * Adding a provider is a code change (a definition here plus its driver); configuring one never is.
 * Field lists are confirmed against each provider's documentation when its driver is built
 * (backlog CORE-802 payments, CORE-803 SMPP, CORE-804 ZeptoMail, CORE-805 Google Analytics).
 */
class IntegrationCatalog
{
    /**
     * @return array<string, IntegrationDefinition> keyed by provider
     */
    public function all(): array
    {
        $definitions = [
            new IntegrationDefinition('paystack', IntegrationType::Payment, 'Paystack',
                'Cards, bank transfer and USSD. Webhooks are signed with the secret key.', [
                    new IntegrationField('public_key', 'Public key'),
                    IntegrationField::secret('secret_key', 'Secret key', 'Also used to verify webhooks. Set the webhook URL in Paystack to /api/webhooks/paystack on this site.'),
                    new IntegrationField('callback_url', 'Callback URL', required: false, help: 'Where Paystack sends the payer after checkout. Leave blank to use the URL set in your Paystack dashboard.', pattern: '#^https://#'),
                ], PaystackTester::class),
            new IntegrationDefinition('squad', IntegrationType::Payment, 'Squad (GTCO)',
                'GTCO Squad payments. Sandbox keys start with sandbox_sk_.', [
                    new IntegrationField('public_key', 'Public key', required: false, help: 'Only needed if you also use the Squad payment modal in the browser.'),
                    IntegrationField::secret('secret_key', 'Secret key', 'Also used to verify webhooks. In Squad, set the webhook URL to /api/webhooks/squad on this site (Profile → API & Webhook).'),
                    IntegrationField::select('environment', 'Environment', ['sandbox', 'live']),
                    new IntegrationField('callback_url', 'Callback URL', required: false, help: 'Where Squad sends the payer after checkout.', pattern: '#^https://#'),
                ], SquadTester::class),
            new IntegrationDefinition('flutterwave', IntegrationType::Payment, 'Flutterwave',
                'Flutterwave Standard (hosted payment page). Test and live keys use the same API.', [
                    new IntegrationField('public_key', 'Public key', required: false, help: 'Only needed if you also use Flutterwave Inline in the browser.'),
                    IntegrationField::secret('secret_key', 'Secret key', 'Starts with FLWSECK_TEST- or FLWSECK-.'),
                    IntegrationField::secret('webhook_secret_hash', 'Webhook secret hash', 'Any long random value. Enter the same value in Flutterwave → Settings → Webhooks, and set the webhook URL to /api/webhooks/flutterwave on this site.'),
                    new IntegrationField('redirect_url', 'Redirect URL', help: 'Where Flutterwave sends the payer after checkout (required by Flutterwave).', pattern: '#^https://#'),
                ], FlutterwaveTester::class),
            new IntegrationDefinition('opay', IntegrationType::Payment, 'OPay',
                'OPay cashier (Nigeria). Payment callbacks are sent to /api/webhooks/opay on this site automatically.', [
                    new IntegrationField('merchant_id', 'Merchant ID'),
                    new IntegrationField('public_key', 'Public key', help: 'Starts with OPAYPUB. Used to create payments.'),
                    IntegrationField::secret('secret_key', 'Secret (private) key', 'Starts with OPAYPRV. Signs status checks and verifies callbacks.'),
                    IntegrationField::select('environment', 'Environment', ['sandbox', 'live']),
                    new IntegrationField('return_url', 'Return URL', help: 'Where OPay sends the payer after checkout (required by OPay).', pattern: '#^https://#'),
                ], OpayTester::class),
            new IntegrationDefinition('moniepoint', IntegrationType::Payment, 'Moniepoint (Monnify)',
                'Moniepoint / Monnify collections.', [
                    new IntegrationField('api_key', 'API key'),
                    IntegrationField::secret('secret_key', 'Secret key'),
                    new IntegrationField('contract_code', 'Contract code'),
                    IntegrationField::select('environment', 'Environment', ['sandbox', 'live']),
                ]),
            // TelKo (TELKO-MS, Lagos) issues SMPP accounts on request; there is no self-serve sign-up, so the links lead to their team.
            new IntegrationDefinition('smpp', IntegrationType::Sms, 'TelKo SMS (SMPP)',
                'Send SMS through a TelKo A2P bulk SMS account over SMPP v3.4. No account yet? Open settings for TelKo’s contact details. Any other SMPP v3.4 provider works too. Run `php artisan sms:receipts` under a process supervisor to record delivery receipts.', [
                    new IntegrationField('host', 'Host'),
                    new IntegrationField('port', 'Port', input: 'number', help: 'Usually 2775.'),
                    new IntegrationField('system_id', 'System ID'),
                    IntegrationField::secret('password', 'Password'),
                    new IntegrationField('system_type', 'System type', required: false),
                    new IntegrationField('source_address', 'Sender ID', help: 'The approved sender name (max 11 characters) or number.'),
                    IntegrationField::select('bind_mode', 'Bind mode', ['transmitter', 'transceiver'], 'Use transceiver if your SMSC sends delivery receipts on the same session.'),
                    new IntegrationField('default_country_code', 'Default country code', required: false, help: 'Used for local numbers such as 0803…; defaults to 234 (Nigeria).', pattern: '/^\d{1,4}$/'),
                ], SmppTester::class, [
                    ['label' => 'Request a TelKo SMS account', 'url' => 'https://telko-ms.com/contact'],
                    ['label' => 'About TelKo bulk SMS', 'url' => 'https://telko-ms.com/services/bulk-sms'],
                    ['label' => 'Email servicedesk@telko-ms.com', 'url' => 'mailto:servicedesk@telko-ms.com'],
                    ['label' => 'Call +234 904 944 4444', 'url' => 'tel:+2349049444444'],
                    ['label' => 'Chat on WhatsApp', 'url' => 'https://wa.me/2349049444444'],
                ]),
            new IntegrationDefinition('zeptomail', IntegrationType::Mail, 'Zoho ZeptoMail',
                'Transactional email (verification, password reset, invitations, notifications).', [
                    IntegrationField::secret('api_token', 'Send Mail token', 'From Mail Agents → SMTP/API → API in ZeptoMail. Paste the token only, without "Zoho-enczapikey".'),
                    new IntegrationField('api_host', 'API host', help: 'The host in the API URL shown in your ZeptoMail account, e.g. cpaas.zoho.com.', pattern: '/^([a-z0-9-]+\.)+(zoho|zeptomail)(\.[a-z]{2,})+$/i'),
                    new IntegrationField('from_address', 'From address', input: 'email', help: 'Must belong to a domain verified in ZeptoMail.'),
                    new IntegrationField('from_name', 'From name'),
                ], ZeptoMailTester::class),
            // No tester: Google serves the tag for any well-formed ID, so a "test" could not tell a typo from a real stream.
            new IntegrationDefinition('google_analytics', IntegrationType::Analytics, 'Google Analytics 4',
                'Loads Google Analytics on every page, but only for visitors who accept analytics cookies (Admin → Consent notices). Page changes inside the app are counted by GA4 enhanced measurement ("Page changes based on browser history events", on by default). After enabling, check Realtime in Google Analytics.', [
                    new IntegrationField('measurement_id', 'Measurement ID', help: 'In Google Analytics: Admin → Data streams → your web stream, e.g. G-ABC123XYZ9.', pattern: '/^G-[A-Z0-9]{4,20}$/'),
                ]),
            // Blog and campaign pictures (App\Services\Media\MediaStorage). With neither on, they stay on this server.
            new IntegrationDefinition(MediaStorage::CDN, IntegrationType::Storage, 'CDN for files on this server',
                'Pictures stay on this server, but visitors load them through your CDN (Cloudflare, BunnyCDN, Amazon CloudFront and similar), which is faster and lighter on the server. Create a pull zone whose origin is this site, then enter its address. Ignored while the storage bucket below is on.', [
                    new IntegrationField('url', 'CDN address', help: 'The address that serves this site’s /storage folder, e.g. https://cdn.example.com/storage. Open a picture through it to check before switching it on.', pattern: '/^https:\/\/[^\s?#]+$/'),
                ]),
            new IntegrationDefinition(MediaStorage::BUCKET, IntegrationType::Storage, 'S3-compatible storage bucket',
                'Uploads new blog and campaign pictures to a storage bucket instead of this server: Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi and others. Pictures already uploaded stay where they are and keep working, so leave the bucket online even if you switch this off. The bucket must let visitors read files (a public bucket policy, a public bucket address, or a CDN in front of it).', [
                    new IntegrationField('bucket', 'Bucket name'),
                    new IntegrationField('region', 'Region', help: 'For example eu-west-2 for AWS, auto for Cloudflare R2, fra1 for DigitalOcean.'),
                    new IntegrationField('endpoint', 'Endpoint', required: false, help: 'Leave blank for Amazon S3. Otherwise the provider’s S3 endpoint, e.g. https://<account>.r2.cloudflarestorage.com or https://fra1.digitaloceanspaces.com.', pattern: '/^https:\/\/[^\s?#]+$/'),
                    IntegrationField::secret('access_key_id', 'Access key ID', 'From an access key that can read, write and delete objects in this bucket only.'),
                    IntegrationField::secret('secret_access_key', 'Secret access key'),
                    new IntegrationField('public_url', 'Public or CDN address', required: false, help: 'Where visitors load the files, e.g. https://media.example.com or your R2 public bucket address. Leave blank to use the bucket’s own address.', pattern: '/^https:\/\/[^\s?#]+$/'),
                    new IntegrationField('path_style', 'Path-style addresses', input: 'select', required: false, options: ['no', 'yes'], help: 'Only needed for some self-hosted servers such as MinIO.'),
                ], BucketStorageTester::class),
            new IntegrationDefinition(AnthropicApi::PROVIDER, IntegrationType::Ai, 'Anthropic Claude',
                'Claude models for AI features such as the site assistant and the Aegis security analyst (choose the analyst\'s model and switch it on in Admin → Security → Settings). The analyst receives a redacted summary of each incident (no names, emails or IP addresses) and explains it for an administrator; it never acts on accounts.', [
                    IntegrationField::secret('api_key', 'API key', 'From console.anthropic.com → API keys. Starts with sk-ant-.'),
                    IntegrationField::select('model', 'Model', AnthropicApi::MODELS, 'claude-opus-5-5 gives the most careful answers; claude-sonnet-5-5 and claude-haiku-5-5 cost less.'),
                    new IntegrationField('workspace_id', 'Workspace ID', required: false, help: 'Only for API keys that are not tied to a workspace: the ID of the workspace to use, from console.anthropic.com → Settings → Workspaces (starts with wrkspc_). Leave blank for keys created inside a workspace.', pattern: '/^wrkspc_[A-Za-z0-9]+$/'),
                ], AnthropicTester::class),
            new IntegrationDefinition('openai', IntegrationType::Ai, 'OpenAI',
                'OpenAI models for AI features. "Test connection" sends a tiny prompt (a few tokens) to check the key and model together.', [
                    IntegrationField::secret('api_key', 'API key', 'From platform.openai.com → API keys. Starts with sk-.'),
                    new IntegrationField('model', 'Model', required: false, help: 'A model ID from your OpenAI account. Leave blank to use the SDK default.'),
                ], OpenAiTester::class),
            new IntegrationDefinition('gemini', IntegrationType::Ai, 'Google Gemini',
                'Google Gemini models for AI features. "Test connection" sends a tiny prompt (a few tokens) to check the key and model together.', [
                    IntegrationField::secret('api_key', 'API key', 'From aistudio.google.com → Get API key.'),
                    new IntegrationField('model', 'Model', required: false, help: 'A Gemini model ID. Leave blank to use the SDK default.'),
                ], GeminiTester::class),
            new IntegrationDefinition('openrouter', IntegrationType::Ai, 'OpenRouter',
                'One key for models from many labs, billed through OpenRouter. "Test connection" sends a tiny prompt (a few tokens) to check the key and model together.', [
                    IntegrationField::secret('api_key', 'API key', 'From openrouter.ai → Keys. Starts with sk-or-.'),
                    new IntegrationField('model', 'Model', required: false, help: 'An OpenRouter model ID such as anthropic/claude-sonnet-5.5. Leave blank to use the SDK default.'),
                ], OpenRouterTester::class),
            new IntegrationDefinition('ollama', IntegrationType::Ai, 'Ollama (self-hosted)',
                'Models running on your own Ollama server, so prompts never leave your infrastructure. The server must be reachable from this application.', [
                    new IntegrationField('url', 'Server URL', help: 'For example http://localhost:11434 or https://ollama.internal.example.com.', pattern: '/^https?:\/\/\S+$/'),
                    new IntegrationField('model', 'Model', help: 'A model you have pulled on the server, e.g. qwen3.5:4b (see `ollama list`).'),
                    new IntegrationField('api_key', 'API key', required: false, secret: true, help: 'Only if your server sits behind an authenticating proxy.'),
                ], OllamaTester::class),
            new IntegrationDefinition('openai_compatible', IntegrationType::Ai, 'OpenAI-compatible server',
                'Any server that speaks the OpenAI API: LM Studio, vLLM, LocalAI, Together and similar. The server must be reachable from this application.', [
                    new IntegrationField('url', 'Base URL', help: 'The API base, usually ending in /v1, e.g. http://localhost:1234/v1.', pattern: '/^https?:\/\/\S+$/'),
                    new IntegrationField('model', 'Model', help: 'The model ID the server expects.'),
                    new IntegrationField('api_key', 'API key', required: false, secret: true, help: 'Leave blank if the server does not need one.'),
                ], OpenAiCompatibleTester::class),
            new IntegrationDefinition(AssistantWidget::PROVIDER, IntegrationType::Assistant, 'Third-party assistant widget',
                'An external chat widget using the embed.js protocol. Choose Third-party widget in Site assistant to show it. Ìpìlẹ̀’s built-in assistant guard rails do not cover this service.', [
                    new IntegrationField('base_url', 'Widget address', help: 'The HTTPS origin only, e.g. https://chat.example.com. No path, query or credentials.', pattern: AssistantWidget::ORIGIN_PATTERN),
                    new IntegrationField('integration_id', 'Integration ID', help: 'Copy the UUID from the widget administrator.'),
                    IntegrationField::select('access_mode', 'Access mode', ['static', 'signed'], 'Signed access identifies each person by a mapped role. Static access gives everyone the same access profile.'),
                    new IntegrationField('shared_secret', 'Shared secret', required: false, secret: true, help: 'Required for signed access (at least 32 characters). Stored encrypted; leave blank to keep the saved secret.'),
                    new IntegrationField('role_mapping', 'Role mapping', required: false, help: 'For signed access, enter a JSON object such as {"admin":"staff","member":"customer"}. Uses global site roles, not team roles. First matching entry wins; unmapped accounts get no access.'),
                    new IntegrationField('claim_seconds', 'Claim lifetime (seconds)', input: 'number', required: false, help: '1–300 seconds; defaults to 300. Must not exceed the lifetime set by the widget administrator.'),
                    new IntegrationField('data_access', 'Data access', input: 'select', required: false, options: ['off', 'on'], help: 'On lets the widget service read site data for each person (help articles, their own access, allowed business figures) at /mcp/widgets. Needs signed access. Every read is recorded in Aegis.'),
                    new IntegrationField('data_networks', 'Data access networks', required: false, help: 'Required when data access is on: the widget service\'s IP addresses or CIDR ranges, separated by commas. Requests from anywhere else are refused.'),
                ]),
        ];

        $keyed = [];
        foreach ($definitions as $definition) {
            $keyed[$definition->provider] = $definition;
        }

        return $keyed;
    }

    public function find(string $provider): ?IntegrationDefinition
    {
        return $this->all()[$provider] ?? null;
    }
}
