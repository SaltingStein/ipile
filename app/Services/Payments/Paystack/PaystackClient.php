<?php

namespace App\Services\Payments\Paystack;

use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Paystack REST client. Endpoints and fields follow Paystack's published OpenAPI spec
 * (github.com/PaystackOSS/openapi), checked 2026-10-14.
 */
class PaystackClient
{
    public const BASE_URL = 'https://api.paystack.co';

    private const TIMEOUT_SECONDS = 20;

    /**
     * POST /transaction/initialize. Amount is in the currency's smallest unit (kobo for NGN).
     *
     * @param  array{secret_key: string}  $config
     * @param  array{email: string, amount: int, currency: string, reference: string, callback_url?: string, metadata?: array<string, mixed>}  $transaction
     * @return array{authorization_url: string, access_code: string, reference: string}
     *
     * @throws PaymentGatewayException
     */
    public function initialize(array $config, array $transaction): array
    {
        return $this->data($this->request($config, fn (PendingRequest $http) => $http->post('/transaction/initialize', $transaction)));
    }

    /**
     * GET /transaction/verify/{reference}.
     *
     * @param  array{secret_key: string}  $config
     * @return array<string, mixed> The transaction (status, amount in kobo, currency, reference, ...)
     *
     * @throws PaymentGatewayException
     */
    public function verify(array $config, string $reference): array
    {
        return $this->data($this->request($config, fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference))));
    }

    /**
     * GET /balance: a cheap authenticated call used to check the secret key.
     *
     * @param  array{secret_key: string}  $config
     *
     * @throws PaymentGatewayException
     */
    public function checkCredentials(array $config): void
    {
        $this->data($this->request($config, fn (PendingRequest $http) => $http->get('/balance')));
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
            throw new PaymentGatewayException('Could not reach Paystack. Please try again.');
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
            throw new PaymentGatewayException('Paystack rejected the secret key.');
        }

        if ($response->failed() || $response->json('status') !== true) {
            throw new PaymentGatewayException('Paystack: '.($response->json('message') ?? 'request failed with HTTP '.$response->status()).'.');
        }

        return (array) $response->json('data');
    }
}
