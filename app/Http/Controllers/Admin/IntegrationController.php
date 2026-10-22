<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationDefinition;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Integrations: credentials and switches for payment, SMS and email providers.
 * Secrets are write-only: they are stored encrypted and never sent back to the browser.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private IntegrationCatalog $catalog,
        private IntegrationManager $integrations,
    ) {}

    /**
     * Show every provider grouped by type.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Integrations', [
            'groups' => $this->integrations->forDashboard(),
        ]);
    }

    /**
     * Show one provider's settings page.
     */
    public function show(string $provider): Response
    {
        $definition = $this->definition($provider);
        $groups = $this->integrations->forDashboard();

        $group = collect($groups)->first(
            fn (array $entry) => collect($entry['providers'] ?? [])->contains(
                fn (array $candidate) => ($candidate['provider'] ?? null) === $definition->provider
            )
        );

        abort_if($group === null, 404);

        $selectedProvider = collect($group['providers'])->first(
            fn (array $candidate) => ($candidate['provider'] ?? null) === $definition->provider
        );

        abort_if($selectedProvider === null, 404);

        return Inertia::render('Admin/IntegrationProvider', [
            'groupLabel' => $group['label'],
            'provider' => $selectedProvider,
            'indexUrl' => route('admin.integrations.index'),
        ]);
    }

    /**
     * Save one provider's settings.
     */
    public function update(Request $request, string $provider): RedirectResponse
    {
        $definition = $this->definition($provider);

        $this->integrations->update($definition, $request->validate($this->integrations->rules($definition)));

        return back()->with('status', __(':provider settings saved.', ['provider' => $definition->label]));
    }

    /**
     * Check the saved credentials against the provider.
     */
    public function test(string $provider): RedirectResponse
    {
        $definition = $this->definition($provider);
        $result = $this->integrations->test($definition);

        if ($result->succeeded === false) {
            return back()->withErrors(["{$provider}.test" => $result->message]);
        }

        return back()->with('status', $result->message);
    }

    private function definition(string $provider): IntegrationDefinition
    {
        $definition = $this->catalog->find($provider);

        abort_if($definition === null, 404);

        return $definition;
    }
}
