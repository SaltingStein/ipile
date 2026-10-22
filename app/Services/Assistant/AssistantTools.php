<?php

namespace App\Services\Assistant;

use App\Ai\Tools\AssistantTool;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * Builders' own read-only tools for the site assistant. A tool extends {@see AssistantTool}, so it always acts for one
 * signed-in person and its queries run under {@see AssistantDataPolicy}.
 *
 *     AssistantTools::register(MyWallet::class);                                  // every mode
 *     AssistantTools::register(PayoutTrends::class, [AssistantIntent::Analytics]); // the staff analytics mode only
 *
 * Call it in a service provider's `boot()`. Tools are built from the container for each message, with the person
 * passed as `user`. Check your own permissions inside `read()`: the assistant may call any tool it is given. A tool
 * limited to staff modes is never offered to the product-facing assistant or the third-party widget.
 *
 * @api
 */
class AssistantTools
{
    public function __construct(private Container $container, private AssistantExtensions $extensions) {}

    /**
     * @param  class-string<AssistantTool>  $class
     * @param  list<AssistantIntent>|null  $modes  The modes that may use it; null for every mode.
     */
    public static function register(string $class, ?array $modes = null): void
    {
        app(AssistantExtensions::class)->registerTool($class, $modes);
    }

    /**
     * The registered tools for this mode, acting for this person.
     *
     * @return list<AssistantTool>
     */
    public function for(User $user, AssistantIntent $mode = AssistantIntent::EndUserSupport): array
    {
        return array_map(fn (string $class) => $this->container->make($class, ['user' => $user]), $this->extensions->toolsFor($mode));
    }
}
