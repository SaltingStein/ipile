<?php

namespace App\Services\Security;

use App\Events\Security\SecurityActionTaken;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * Builders' own consequences for Aegis responses, added with {@see Security::onAction()}: freeze a wallet when an
 * account is suspended, hold payouts after a sign-out everywhere, open a support ticket after an alert.
 *
 * Handlers run in the order they were added, right after the built-in response, for the response actually taken.
 * A handler that throws is reported and skipped; the others and the built-in response are never affected.
 * Registered as a singleton; empty unless a builder adds handlers.
 */
class SecurityResponseHooks
{
    /** @var array<string, list<callable|string>> keyed by {@see SecurityAction} value */
    private array $handlers = [];

    public function __construct(private Container $container) {}

    /**
     * @param  callable(SecurityActionTaken): mixed|class-string  $handler  A closure or callable, or a class with
     *                                                                      `handle()` or `__invoke()` (resolved from
     *                                                                      the container when it runs).
     */
    public function on(SecurityAction $action, callable|string $handler): void
    {
        if ($action === SecurityAction::None) {
            throw new InvalidArgumentException('Handlers attach to a response; "None" is not one.');
        }

        if (is_string($handler) && ! is_callable($handler) && ! class_exists($handler)) {
            throw new InvalidArgumentException("Security response handler [{$handler}] is not a class or callable.");
        }

        $this->handlers[$action->value][] = $handler;
    }

    public function run(SecurityActionTaken $taken): void
    {
        foreach ($this->handlers[$taken->action->value] ?? [] as $handler) {
            try {
                $this->resolve($handler)($taken);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function resolve(callable|string $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }

        $instance = $this->container->make($handler);

        return method_exists($instance, 'handle') ? $instance->handle(...) : $instance;
    }
}
