<?php

namespace App\Http\Controllers;

use App\Services\ConsentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsentController extends Controller
{
    public function config(ConsentSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->config()]);
    }

    public function store(Request $request, ConsentSettings $settings): RedirectResponse|JsonResponse
    {
        $config = $settings->config();
        $data = $request->validate([
            'version' => ['required', 'integer', Rule::in([$config['version']])],
            'analytics' => ['required', 'boolean'], 'marketing' => ['required', 'boolean'],
        ], ['version.in' => 'Privacy notices changed. Reload and choose again.']);
        foreach (['analytics', 'marketing'] as $category) {
            if ($data[$category] && ! $config[$category.'_enabled']) {
                throw ValidationException::withMessages([$category => 'This category is disabled.']);
            }
        }
        $choice = [
            'id' => (string) Str::uuid(), 'version' => $config['version'],
            'necessary' => true, 'analytics' => (bool) $data['analytics'], 'marketing' => (bool) $data['marketing'],
            'expires_at' => now()->addDays($config['expiry_days'])->timestamp,
        ];
        DB::table('consent_records')->insert([
            'id' => $choice['id'], 'user_id' => $request->user()?->id, 'version' => $config['version'],
            'choices' => json_encode($choice), 'notice_snapshot' => json_encode($config), 'created_at' => now(),
        ]);
        if ($request->expectsJson()) {
            return response()->json(['data' => $choice], 201);
        }

        return back()->withCookie(cookie(ConsentSettings::COOKIE, json_encode($choice), $config['expiry_days'] * 1440, '/', null, $request->isSecure(), true, false, 'lax'));
    }
}
