<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Host-side configuration and short-lived claims for the embed.js protocol in the integration guide.
 */
class AssistantWidget
{
    public const PROVIDER = 'assistant_widget';

    public const MAX_CLAIM_SECONDS = 300;

    public const ORIGIN_PATTERN = '#^https://[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?(?::[0-9]{1,5})?/?$#D';

    public function __construct(private AssistantSettings $settings, private IntegrationManager $integrations) {}

    /**
     * Validate the merged configuration, including a previously saved write-only secret.
     *
     * @param  array<string, mixed>  $config
     */
    public static function validateConfig(array $config): void
    {
        $signed = ($config['access_mode'] ?? null) === 'signed';
        $dataAccess = ($config['data_access'] ?? null) === 'on';
        Validator::make(['config' => $config], [
            'config.base_url' => ['required', 'string', 'url:https', 'regex:'.self::ORIGIN_PATTERN],
            'config.integration_id' => ['required', 'uuid'],
            'config.access_mode' => ['required', 'in:static,signed'],
            'config.shared_secret' => [$signed ? 'required' : 'nullable', 'string', 'min:32'],
            'config.claim_seconds' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_CLAIM_SECONDS],
            'config.data_access' => ['nullable', 'in:off,on'],
            'config.data_networks' => [$dataAccess ? 'required' : 'nullable', 'string', 'max:1000'],
        ])->validate();

        if ($signed && self::roleMapping($config) === []) {
            throw ValidationException::withMessages([
                'config.role_mapping' => __('Enter a role mapping as a JSON object, for example {"admin":"staff","member":"customer"}. The first matching site role is used.'),
            ]);
        }

        if ($dataAccess && ! $signed) {
            throw ValidationException::withMessages([
                'config.data_access' => __('Data access needs signed access, so every request is tied to one person.'),
            ]);
        }

        if ($dataAccess && self::networks($config) === []) {
            throw ValidationException::withMessages([
                'config.data_networks' => __('Enter the widget service\'s IP addresses or ranges, separated by commas, for example 203.0.113.10, 198.51.100.0/24.'),
            ]);
        }
    }

    /**
     * The networks allowed to use the data connection; empty when any entry is not an IP address or CIDR range.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function networks(array $config): array
    {
        $entries = preg_split('/[\s,]+/', trim((string) ($config['data_networks'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($entries as $entry) {
            [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $maxBits = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

            if (filter_var($address, FILTER_VALIDATE_IP) === false || ($bits !== null && (! ctype_digit($bits) || (int) $bits > $maxBits))) {
                return [];
            }
        }

        return $entries;
    }

    /**
     * The configuration when the widget may read site data through the data connection (`/mcp/widgets`).
     *
     * @return array<string, mixed>|null
     */
    public function dataAccessConfig(): ?array
    {
        $config = $this->config();

        return $config !== null && self::allowsDataAccess($config) ? $config : null;
    }

    /**
     * The person a claim belongs to, when the claim is genuine, unexpired and still matches their account: not
     * suspended, not signed out everywhere since, password unchanged and the same mapped role.
     *
     * The widget service knows the shared secret, so it could sign a claim itself; the `ref` inside is encrypted with
     * the app key and carries the person, the expiry and a fingerprint of their sign-in state, so a forged or
     * re-dated claim is refused.
     *
     * @param  array<string, mixed>  $config
     */
    public function userForClaim(string $claim, array $config): ?User
    {
        $parts = explode('.', $claim);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', $header.'.'.$payload, $config['shared_secret'], true));
        if (! hash_equals($expected, $signature) || ($this->decodeJson($header)['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $payload = $this->decodeJson($payload);
        $expiresAt = $payload['exp'] ?? null;
        if (! is_int($expiresAt) || $expiresAt <= now()->timestamp || $expiresAt > now()->timestamp + self::MAX_CLAIM_SECONDS
            || ! is_string($payload['ref'] ?? null) || ! is_string($payload['role'] ?? null) || ! is_string($payload['visitor'] ?? null)) {
            return null;
        }

        try {
            $reference = json_decode(Crypt::decryptString($payload['ref']), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($reference) || ($reference['i'] ?? null) !== $config['integration_id'] || ($reference['e'] ?? null) !== $expiresAt) {
            return null;
        }

        $user = User::query()->find($reference['u'] ?? null);

        if ($user === null || $user->is_guest || $user->isSuspended()
            || ! hash_equals($this->signInFingerprint($user), (string) ($reference['s'] ?? ''))
            || ! hash_equals($this->visitorFor($user, $config), $payload['visitor'])
            || $this->roleFor($user, $config) !== $payload['role']) {
            return null;
        }

        return $user;
    }

    /**
     * Only public loader options reach the browser, never the secret or the role mapping.
     *
     * @return array{base_url: string, integration_id: string, claim_endpoint: ?string, revision: string}|null
     */
    public function forUser(?User $user): ?array
    {
        $config = $this->configFor($user);

        if ($config === null || ($config['access_mode'] === 'signed' && $this->roleFor($user, $config) === null)) {
            return null;
        }

        return [
            'base_url' => rtrim($config['base_url'], '/'),
            'integration_id' => $config['integration_id'],
            'claim_endpoint' => $config['access_mode'] === 'signed' ? route('assistant.widget-claim') : null,
            'revision' => hash_hmac('sha256', json_encode([$user->id, $user->getRoleNames()->all(), $config]), config('app.key')),
        ];
    }

    public function claim(User $user): string
    {
        $config = $this->configFor($user);
        abort_unless($config !== null && $config['access_mode'] === 'signed', 404);
        $role = $this->roleFor($user, $config);
        abort_if($role === null, 403);

        $expiresAt = now()->timestamp + (int) ($config['claim_seconds'] ?? self::MAX_CLAIM_SECONDS);
        $claim = [
            'role' => $role,
            'visitor' => $this->visitorFor($user, $config),
            'exp' => $expiresAt,
        ];

        // Only for the data connection: the widget service forwards the claim as its bearer token (see userForClaim()).
        if (self::allowsDataAccess($config)) {
            $claim['ref'] = Crypt::encryptString((string) json_encode([
                'i' => $config['integration_id'], 'u' => $user->getKey(), 'e' => $expiresAt, 's' => $this->signInFingerprint($user),
            ]));
        }

        $header = $this->encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->encode(json_encode($claim));
        $signature = $this->encode(hash_hmac('sha256', $header.'.'.$payload, $config['shared_secret'], true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function allowsDataAccess(array $config): bool
    {
        return ($config['data_access'] ?? null) === 'on' && $config['access_mode'] === 'signed';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function visitorFor(User $user, array $config): string
    {
        return hash_hmac('sha256', 'widget-visitor:'.$config['integration_id'].':'.$user->id, $config['shared_secret']);
    }

    /**
     * Changes when the person signs out everywhere (Aegis or their own Security page rotate the remember token) or
     * changes their password, which ends every claim issued before.
     */
    private function signInFingerprint(User $user): string
    {
        return hash_hmac('sha256', 'widget-sign-in:'.$user->getRememberToken().'|'.$user->getAuthPassword(), (string) config('app.key'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment): array
    {
        $base64 = strtr($segment, '-_', '+/');
        $decoded = base64_decode(str_pad($base64, (int) ceil(strlen($base64) / 4) * 4, '='), true);
        $value = $decoded === false ? null : json_decode($decoded, true);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function configFor(?User $user): ?array
    {
        if ($user === null || $user->is_guest || $user->isSuspended()) {
            return null;
        }

        return $this->config();
    }

    /**
     * The enabled, valid widget configuration while the third-party widget is the chosen assistant source.
     *
     * @return array<string, mixed>|null
     */
    private function config(): ?array
    {
        if ($this->settings->source() !== 'third_party') {
            return null;
        }

        $config = $this->integrations->enabledConfig(self::PROVIDER);
        if ($config === null) {
            return null;
        }

        try {
            self::validateConfig($config);
        } catch (ValidationException) {
            return null;
        }

        return $config;
    }

    /**
     * Mapping order is explicit: the first matching global site role wins, with no implicit admin bypass.
     *
     * @param  array<string, mixed>  $config
     */
    private function roleFor(User $user, array $config): ?string
    {
        foreach (self::roleMapping($config) as $siteRole => $widgetRole) {
            if ($user->hasRole($siteRole)) {
                return $widgetRole;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private static function roleMapping(array $config): array
    {
        $mapping = json_decode((string) ($config['role_mapping'] ?? ''), false);
        if (! is_object($mapping)) {
            return [];
        }

        $roles = get_object_vars($mapping);
        foreach ($roles as $siteRole => $widgetRole) {
            if (! is_string($siteRole) || trim($siteRole) === '' || ! is_string($widgetRole) || trim($widgetRole) === '' || mb_strlen($widgetRole) > 100) {
                return [];
            }
        }

        return $roles;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
