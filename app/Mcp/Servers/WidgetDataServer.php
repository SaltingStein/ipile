<?php

namespace App\Mcp\Servers;

use App\Http\Middleware\AuthenticateWidgetData;
use App\Services\Assistant\WidgetDataTools;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The data connection for the third-party assistant widget: read-only tools acting for the one person whose signed
 * claim came with the request ({@see AuthenticateWidgetData}). Served at `/mcp/widgets` (`routes/ai.php`).
 */
#[Name('Site data for the assistant widget')]
#[Version('1.0.0')]
#[Instructions('Read-only data for the one signed-in person the bearer claim belongs to. Every tool answers only within that person\'s own access: help articles they can see, their own roles and permissions, and the business figures an administrator allowed for them. Never guess data a tool does not return.')]
class WidgetDataServer extends Server
{
    protected function boot(): void
    {
        $user = request()->user();

        $this->tools = $user === null ? [] : app(WidgetDataTools::class)->for($user);
    }
}
