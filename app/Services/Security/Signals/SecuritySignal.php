<?php

namespace App\Services\Security\Signals;

use App\Services\Security\Security;

/**
 * One piece of evidence that an event is risky. Register custom signals with {@see Security::signal()}.
 *
 * A signal's contribution is `weight × score`; contributions are combined with a noisy-OR, so any single strong
 * signal can raise the risk on its own and several weak ones add up without ever passing 100.
 */
interface SecuritySignal
{
    /**
     * Stable identifier stored with each event, e.g. `new_device`.
     */
    public function key(): string;

    /**
     * Short name shown in the security centre, e.g. "New device".
     */
    public function label(): string;

    /**
     * What the signal looks at, shown on the Monitoring guide page.
     */
    public function description(): string;

    /**
     * 0-1: how much a full-strength signal counts on its own.
     */
    public function weight(): float;

    /**
     * Score the event. Keep this fast (indexed queries only): it runs inside the request that caused the event.
     */
    public function evaluate(SignalContext $context): SignalResult;
}
