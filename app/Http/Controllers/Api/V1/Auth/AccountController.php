<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Account\AccountDeletion;
use App\Services\Account\AccountDeletionException;
use App\Services\Account\PersonalDataExport;
use App\Services\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The authenticated API user: profile, logout and verification email.
 */
class AccountController extends Controller
{
    /**
     * GET /api/v1/auth/user
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * POST /api/v1/auth/logout — revoke the token used for this request.
     */
    /**
     * Everything held about the signed-in person, as JSON (NDPR / GDPR right of access).
     */
    public function export(Request $request, PersonalDataExport $export): JsonResponse
    {
        Audit::record('account.data_exported', $request->user());

        return response()->json(['data' => $export->for($request->user())]);
    }

    /**
     * Delete the signed-in person's account. Send `confirm: "DELETE"`. App stores require apps to offer this.
     */
    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse|Response
    {
        $request->validate(['confirm' => ['required', 'in:DELETE']]);

        try {
            $deletion->delete($request->user());
        } catch (AccountDeletionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * POST /api/v1/auth/email/verification-notification
     */
    public function sendVerificationEmail(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => __('Your email address is already verified.')]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => __('A new verification link has been sent to your email address.')], 202);
    }
}
