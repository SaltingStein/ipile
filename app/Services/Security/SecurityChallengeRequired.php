<?php

namespace App\Services\Security;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The person must confirm it is them before continuing: a fresh authenticator code by default (see
 * {@see FreshTwoFactor}). Renders itself: browsers go to the confirmation page and return to where they were;
 * JSON callers get `423 Locked` with `confirm_url`.
 *
 * @api
 */
class SecurityChallengeRequired extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message = '',
        public ?string $confirmUrl = null,
        public ?SecurityDecision $decision = null,
    ) {
        parent::__construct($message !== '' ? $message : SecurityDecision::challengeReason());
    }

    public function render(Request $request): Response
    {
        $url = $this->confirmUrl ?? route('security.confirm');

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'confirm_url' => $url], 423);
        }

        return redirect()->guest($url)->with('status', $this->getMessage());
    }
}
