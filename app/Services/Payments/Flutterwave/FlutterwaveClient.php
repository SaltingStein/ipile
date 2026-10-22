<?php

namespace App\Services\Payments\Flutterwave;

use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Flutterwave v3 client, following developer.flutterwave.com (checked 2026-10-14):
 * Flutterwave Standard, Verify transaction with reference and Retrieve wallet balances.
 * Test and live keys use the same base URL. Amounts are in major units (naira), not kobo.
 */
class FlutterwaveClient
{
    public const BASE_URL = 'https://api.flutterwave.com/v3';

    private const TIMEOUT_SECONDS = 20;

    /**
     * POST /payments (Flutterwave Standard): returns the hosted payment link.
     *
     * @param  array{secret_key: string}  $config
     * @param  array<string, mixed>  $payment
     * @return array{link: string}
     *
     * @throws PaymentGatewayException
     */
    public function createPaymentLink(array $config, array $payment): array
    {
        return $this->data($this->request($config, fn (PendingRequest $http) => $http->post('/payments', $payment)));
    }

    /**
     * GET /transactions/verify_by_reference?tx_ref=... (`status` is successful, failed or pending).
     *
     * @param  array{secret_key: string}  $config
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    public function verifyByReference(array $config, string $txRef): array
    {
        return $this->data($this->request($config, fn (PendingRequest $http) => $http->get('/transactions/verify_by_reference', ['tx_ref' => $txRef])));
    }

    /**
     * GET /balances: a read-only call used to check the secret key.
     *
     * @param  array{secret_key: string}  $config
     *
     * @throws PaymentGatewayException
     */
    public function checkCredentials(array $config): void
    {
        $this->data($this->request($config, fn (PendingRequest $http) => $http->get('/balances')));
    }

    /**
     * @param  array{secret_key: string}  $config
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws PaymentGatewayException
     */
    private function request(array $config, callable $send): Response
    {
        try {
            return $send(Http::baseUrl(self::BASE_URL)
                ->withToken($config['secret_key'])
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS));
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Could not reach Flutterwave. Please try again.');
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function data(Response $response): array
    {
        if ($response->status() === 401) {
            throw new PaymentGatewayException('Flutterwave rejected the secret key.');
        }

        if ($response->failed() || $response->json('status') !== 'success') {
            throw new PaymentGatewayException('Flutterwave: '.rtrim((string) ($response->json('message') ?: 'request failed with HTTP '.$response->status()), '.').'.');
        }

        return (array) $response->json('data');
    }
}
