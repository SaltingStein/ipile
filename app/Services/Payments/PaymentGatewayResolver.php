<?php

namespace App\Services\Payments;

use App\Interfaces\PaymentGatewayInterface;
use App\Services\Payments\Flutterwave\FlutterwaveGateway;
use App\Services\Payments\Opay\OpayGateway;
use App\Services\Payments\Paystack\PaystackGateway;
use App\Services\Payments\Squad\SquadGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Maps a provider key to its gateway strategy and builds it through the container,
 * so controllers never instantiate gateways directly.
 */
class PaymentGatewayResolver
{
    /**
     * @var array<string, class-string<PaymentGatewayInterface>>
     */
    private const GATEWAYS = [
        'paystack' => PaystackGateway::class,
        'squad' => SquadGateway::class,
        'flutterwave' => FlutterwaveGateway::class,
        'opay' => OpayGateway::class,
        'moniepoint' => MoniepointGateway::class,
    ];

    public function __construct(private Container $container) {}

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys(self::GATEWAYS);
    }

    /**
     * Providers that can start a payment right now (implemented and enabled in the dashboard).
     *
     * @return list<string>
     */
    public function availableProviders(): array
    {
        return array_values(array_filter($this->providers(), fn (string $provider) => $this->resolve($provider)->isAvailable()));
    }

    public function supports(string $provider): bool
    {
        return array_key_exists($provider, self::GATEWAYS);
    }

    /**
     * @throws InvalidArgumentException When the provider is not supported.
     */
    public function resolve(string $provider): PaymentGatewayInterface
    {
        if (! $this->supports($provider)) {
            throw new InvalidArgumentException("Unsupported payment provider [{$provider}].");
        }

        return $this->container->make(self::GATEWAYS[$provider]);
    }
}
