<?php

namespace App\Services\Payments\Squad;

use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Squad (GTCO) REST client, following docs.squadco.com (checked 2026-10-14):
 * Payments → Initiate Payment and Verify Transaction.
 */
class SquadClient
{
    public const SANDBOX_URL = 'https://sandbox-api-d.squadco.com';

    public const LIVE_URL = 'https://api-d.squadco.com';

    private const TIMEOUT_SECONDS = 20;

    /**
     * POST /transaction/initiate. Amount is in kobo (or cents for USD).
     *
     * @param  array{secret_key: string, environment: string}  $config
     * @param  array{email: string, amount: int, currency: string, transaction_ref: string, customer_name: string, callback_url?: string, metadata?: array<string, mixed>}  $transaction
     * @return array<string, mixed> Includes `checkout_url` and `transaction_ref`.
     *
     * @throws PaymentGatewayException
     */
    public function initiate(array $config, array $transaction): array
    {
        $response = $this->request($config, fn (PendingRequest $http) => $http->post('/transaction/initiate', [
            ...$transaction,
            'initiate_type' => 'inline',
        ]));

        return $this->data($response);
    }

    /**
     * GET /transaction/verify/{transaction_ref}. `transaction_status` is Success, Failed, Abandoned or Pending.
     *
     * @param  array{secret_key: string, environment: string}  $config
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    public function verify(array $config, string $transactionRef): array
    {
        return $this->data($this->request($config, fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($transactionRef))));
    }

    /**
     * Squad has no balance or "who am I" endpoint, so verify a reference that cannot exist: an accepted key
     * gets 400 "Invalid transaction reference", a rejected key gets 401/403.
     *
     * @param  array{secret_key: string, environment: string}  $config
     *
     * @throws PaymentGatewayException
     */
    public function checkCredentials(array $config): void
    {
        $response = $this->request($config, fn (PendingRequest $http) => $http->get('/transaction/verify/ipile-connection-check'));

        if (in_array($response->status(), [401, 403], true)) {
            throw new PaymentGatewayException('Squad rejected the secret key'.$this->reason($response).'.');
        }

        if ($response->serverError()) {
            throw new PaymentGatewayException('Squad is not responding correctly (HTTP '.$response->status().').');
        }
    }

    /**
     * @param  array{secret_key: string, environment: string}  $config
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws PaymentGatewayException
     */
    private function request(array $config, callable $send): Response
    {
        $baseUrl = ($config['environment'] ?? 'sandbox') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        try {
            return $send(Http::baseUrl($baseUrl)
                ->withToken($config['secret_key'])
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS));
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Could not reach Squad. Please try again.');
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function data(Response $response): array
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new PaymentGatewayException('Squad rejected the secret key'.$this->reason($response).'.');
        }

        if ($response->failed() || (int) $response->json('status', $response->status()) !== 200) {
            throw new PaymentGatewayException('Squad: '.($response->json('message') ?: 'request failed with HTTP '.$response->status()).'.');
        }

        return (array) $response->json('data');
    }

    private function reason(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== '' ? ': '.rtrim($message, '.') : '';
    }
}
