<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Account\AccountDeletion;
use App\Services\Account\AccountDeletionException;
use App\Services\Account\PersonalDataExport;
use App\Services\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Settings → Security → Your data: download everything held about you, or delete your account.
 */
class AccountController extends Controller
{
    public function export(Request $request, PersonalDataExport $export): JsonResponse
    {
        $user = $request->user();
        Audit::record('account.data_exported', $user);

        return response()
            ->json($export->for($user), options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Disposition', 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"');
    }

    public function destroy(Request $request, AccountDeletion $deletion): RedirectResponse
    {
        $request->validate([
            'confirm' => ['required', 'in:DELETE'],
        ], ['confirm.in' => __('Type DELETE to confirm.')]);

        try {
            $deletion->delete($request->user());
        } catch (AccountDeletionException $exception) {
            return back()->withErrors(['account' => $exception->getMessage()]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('Your account has been deleted.'));
    }
}
