<?php

namespace App\Services\Payments\Opay;

use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal OPay Nigeria cashier client, following documentation.opaycheckout.com (checked 2026-10-14):
 * Cashier Create Payment, Query Payment Status and API Calls Signature.
 */
class OpayClient
{
    public const SANDBOX_URL = 'https://testapi.opaycheckout.com';

    public const LIVE_URL = 'https://liveapi.opaycheckout.com';

    private const SUCCESS_CODE = '00000';

    /**
     * Codes meaning "this payment does not exist" (the credentials themselves were accepted).
     *
     * @var list<string>
     */
    private const NOT_FOUND_CODES = ['02006', '00012', '02812'];

    private const AUTHENTICATION_FAILED_CODE = '02000';

    private const TIMEOUT_SECONDS = 20;

    /**
     * POST /api/v1/international/cashier/create, authorised with the public key.
     *
     * @param  array{merchant_id: string, public_key: string, environment: string}  $config
     * @param  array<string, mixed>  $order
     * @return array<string, mixed> Includes `cashierUrl`, `orderNo` and `status`.
     *
     * @throws PaymentGatewayException
     */
    public function createCashier(array $config, array $order): array
    {
        $response = $this->send($config, $config['public_key'], '/api/v1/international/cashier/create', $order);

        return $this->data($response);
    }

    /**
     * POST /api/v1/international/cashier/status, authorised with an HMAC-SHA512 signature of the exact body
     * using the secret key. `status` is INITIAL, PENDING, SUCCESS, FAIL or CLOSE.
     *
     * @param  array{merchant_id: string, secret_key: string, environment: string}  $config
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    public function status(array $config, string $reference): array
    {
        return $this->data($this->statusResponse($config, $reference));
    }

    /**
     * Query a reference that cannot exist: "not found" means the merchant ID and secret key were accepted.
     *
     * @param  array{merchant_id: string, secret_key: string, environment: string}  $config
     *
     * @throws PaymentGatewayException
     */
    public function checkCredentials(array $config): void
    {
        $response = $this->statusResponse($config, 'ipile-connection-check');
        $code = (string) $response->json('code');

        if ($code === self::SUCCESS_CODE || in_array($code, self::NOT_FOUND_CODES, true)) {
            return;
        }

        if ($code === self::AUTHENTICATION_FAILED_CODE || in_array($response->status(), [401, 403], true)) {
            throw new PaymentGatewayException('OPay rejected the merchant ID or secret key.');
        }

        throw new PaymentGatewayException($this->errorMessage($response));
    }

    /**
     * @param  array{merchant_id: string, secret_key: string, environment: string}  $config
     *
     * @throws PaymentGatewayException
     */
    private function statusResponse(array $config, string $reference): Response
    {
        $body = json_encode(['country' => 'NG', 'reference' => $reference], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->send($config, hash_hmac('sha512', $body, $config['secret_key']), '/api/v1/international/cashier/status', $body);
    }

    /**
     * Send JSON exactly as given (signed requests must transmit the same bytes that were signed).
     *
     * @param  array{merchant_id: string, environment: string}  $config
     * @param  array<string, mixed>|string  $body
     *
     * @throws PaymentGatewayException
     */
    private function send(array $config, string $bearer, string $path, array|string $body): Response
    {
        $baseUrl = ($config['environment'] ?? 'sandbox') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $json = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            return Http::baseUrl($baseUrl)
                ->withToken($bearer)
                ->withHeaders(['MerchantId' => $config['merchant_id']])
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBody($json, 'application/json')
                ->post($path);
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Could not reach OPay. Please try again.');
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function data(Response $response): array
    {
        if ($response->failed() || (string) $response->json('code') !== self::SUCCESS_CODE) {
            throw new PaymentGatewayException($this->errorMessage($response));
        }

        return (array) $response->json('data');
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message') ?: 'request failed with HTTP '.$response->status();
        $code = $response->json('code');

        return 'OPay: '.rtrim((string) $message, '.').($code ? " ({$code})" : '').'.';
    }
}
