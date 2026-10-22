<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConsentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConsentSettingsController extends Controller
{
    public function edit(Request $request, ConsentSettings $settings): Response
    {
        return Inertia::render('Admin/ConsentSettings', ['settings' => $settings->config(), 'canConfigure' => $request->user()->can('manage_settings')]);
    }

    public function update(Request $request, ConsentSettings $settings): RedirectResponse
    {
        $rules = [
            'accept_label' => ['required', 'string', 'max:60'], 'reject_label' => ['required', 'string', 'max:60'], 'save_label' => ['required', 'string', 'max:60'],
            'notices' => ['required', 'array', 'size:3'],
            'notices.*' => ['array:id,enabled,title,body'],
            'notices.*.id' => ['required', 'distinct', Rule::in(['cookies', 'ndpr', 'gdpr'])],
            'notices.*.enabled' => ['required', 'boolean'],
            'notices.*.title' => ['required', 'string', 'max:120'],
            'notices.*.body' => ['required', 'string', 'max:10000'],
        ];
        $canConfigure = $request->user()->can('manage_settings');
        if ($canConfigure) {
            $rules += ['enabled' => ['required', 'boolean'], 'expiry_days' => ['required', 'integer', 'min:1', 'max:365'], 'analytics_enabled' => ['required', 'boolean'], 'marketing_enabled' => ['required', 'boolean']];
        }
        $data = $request->validate($rules);
        $config = $settings->config();
        if (! $canConfigure) {
            foreach ($data['notices'] as &$notice) {
                $notice['enabled'] = collect($config['notices'])->firstWhere('id', $notice['id'])['enabled'];
            }
            unset($notice);
        }
        $settings->update(array_replace($config, $data));

        return back()->with('status', 'Consent notices saved. Visitors will be asked to choose again.');
    }
}
