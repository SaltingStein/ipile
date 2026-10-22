<?php

namespace App\Http\Middleware;

use App\Mcp\Servers\WidgetDataServer;
use App\Services\Assistant\AssistantWidget;
use App\Services\Security\Security;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the widget data connection ({@see WidgetDataServer}): it exists only while the third-party widget is the
 * chosen assistant with data access on, answers only the configured networks, and needs a current claim from
 * {@see AssistantWidget::claim()} as the bearer token. The claim's person becomes the request's user; nothing else is
 * accepted (no session, no API token). Refusals are recorded as `mcp.access_refused`.
 */
class AuthenticateWidgetData
{
    public function __construct(private AssistantWidget $widget) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->widget->dataAccessConfig();
        abort_if($config === null, 404);

        if (! IpUtils::checkIp((string) $request->ip(), AssistantWidget::networks($config))) {
            return $this->refuse('network', 403, __('This network may not use the data connection.'));
        }

        $user = $this->widget->userForClaim((string) $request->bearerToken(), $config);

        if ($user === null) {
            // Laravel MCP's AddWwwAuthenticateHeader adds the Bearer challenge to this response.
            return $this->refuse('claim', 401, __('Unauthenticated.'));
        }

        Auth::setUser($user);

        return $next($request);
    }

    /**
     * @param  'network'|'claim'  $reason
     */
    private function refuse(string $reason, int $status, string $message): Response
    {
        Security::recordFor(null, 'mcp.access_refused', ['reason' => $reason]);

        return response()->json(['message' => $message], $status);
    }
}
