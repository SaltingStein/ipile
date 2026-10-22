<?php

namespace App\Http\Controllers;

use App\Services\Assistant\AssistantWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantWidgetClaimController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, AssistantWidget $widget): JsonResponse
    {
        return response()->json(['claim' => $widget->claim($request->user())])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
