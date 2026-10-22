<?php

namespace App\Services\Assistant;

use App\Ai\Tools\AssistantTool;
use App\Ai\Tools\BusinessFigures;
use App\Ai\Tools\MyAccess;
use App\Ai\Tools\SearchHelpArticles;
use App\Mcp\Servers\WidgetDataServer;
use App\Mcp\Tools\WidgetDataTool;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * The tools the third-party assistant widget may call through the data connection ({@see WidgetDataServer}).
 *
 * Ìpìlẹ̀ offers help search, the person's own access and the business figures an administrator allowed. The built-in
 * assistant's MyAccount and MyTeams tools are left out: they return names and emails, which this connection does not
 * hand to a third party. Builders opt their own tools in, separately from the built-in assistant:
 *
 *     WidgetDataTools::register(MyWallet::class);   // in a service provider's boot()
 *
 * @api
 */
class WidgetDataTools
{
    /**
     * @var list<class-string<AssistantTool>>
     */
    public const BUILT_IN = [SearchHelpArticles::class, MyAccess::class, BusinessFigures::class];

    public function __construct(private Container $container, private AssistantExtensions $extensions) {}

    /**
     * @param  class-string<AssistantTool>  $class
     */
    public static function register(string $class): void
    {
        app(AssistantExtensions::class)->registerWidgetTool($class);
    }

    /**
     * The widget's tools, acting for this person.
     *
     * @return list<WidgetDataTool>
     */
    public function for(User $user): array
    {
        $classes = array_values(array_unique([...self::BUILT_IN, ...$this->extensions->widgetTools()]));

        return array_map(fn (string $class) => new WidgetDataTool($this->container->make($class, ['user' => $user]), $user), $classes);
    }
}
