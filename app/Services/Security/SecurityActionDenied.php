<?php

namespace App\Services\Security;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aegis decided a risky action must not go ahead. Renders itself: browsers go back with the reason as a `security`
 * validation error; JSON callers get `403`.
 *
 * @api
 */
class SecurityActionDenied extends RuntimeException implements ShouldntReport
{
    public function __construct(public SecurityDecision $decision)
    {
        parent::__construct($decision->reason);
    }

    public function render(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 403);
        }

        return redirect()->back()->withErrors(['security' => $this->getMessage()]);
    }
}
