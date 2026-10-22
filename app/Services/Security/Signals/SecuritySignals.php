<?php

namespace App\Services\Security\Signals;

use App\Services\Security\Security;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The signals every event is scored with: the built-in ones plus any added with {@see Security::signal()}, either
 * as a class or as a configured instance such as `VelocitySignal::for('payouts.requested', max: 5, perMinutes: 60)`.
 * Registered as a singleton.
 */
class SecuritySignals
{
    /** @var list<class-string<SecuritySignal>> */
    private array $classes = [
        SequenceAnomalySignal::class,
        NewDeviceSignal::class,
        NewNetworkSignal::class,
        BurstSignal::class,
        RecentFailuresSignal::class,
    ];

    /** @var array<string, SecuritySignal> configured instances, keyed by {@see SecuritySignal::key()} */
    private array $instances = [];

    public function __construct(private Container $container) {}

    /**
     * Add a signal class (resolved from the container for each event), or a configured instance. An instance with the
     * same key as an earlier one replaces it.
     *
     * @param  class-string<SecuritySignal>|SecuritySignal  $class
     */
    public function register(string|SecuritySignal $class): void
    {
        if ($class instanceof SecuritySignal) {
            $this->instances[$class->key()] = $class;

            return;
        }

        if (! is_subclass_of($class, SecuritySignal::class)) {
            throw new InvalidArgumentException("[{$class}] must implement ".SecuritySignal::class.'.');
        }

        if (! in_array($class, $this->classes, true)) {
            $this->classes[] = $class;
        }
    }

    /**
     * @return list<SecuritySignal>
     */
    public function all(): array
    {
        return [
            ...array_map(fn (string $class) => $this->container->make($class), $this->classes),
            ...array_values($this->instances),
        ];
    }
}
