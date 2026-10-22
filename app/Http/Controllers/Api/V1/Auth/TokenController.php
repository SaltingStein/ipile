<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ApiTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TokenController extends Controller
{
    /**
     * GET /api/v1/auth/tokens
     */
    public function index(Request $request, ApiTokens $tokens): JsonResponse
    {
        return response()->json([
            'data' => $tokens->summaries($request->user(), $request->user()->currentAccessToken()?->getKey()),
        ]);
    }

    /**
     * DELETE /api/v1/auth/tokens/{tokenId} — another user's token id returns 404.
     */
    public function destroy(Request $request, int $tokenId): Response
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();

        abort_if($deleted === 0, 404);

        return response()->noContent();
    }
}
