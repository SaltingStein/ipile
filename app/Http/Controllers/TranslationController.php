<?php

namespace App\Http\Controllers;

use App\Services\Translation\Dictionary;
use App\Services\Translation\StringCollector;
use App\Services\Translation\TranslationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Translations for the public pages, used by the browser while translation is on (Admin → Languages).
 */
class TranslationController extends Controller
{
    /**
     * Every translation for a language the site offers.
     */
    public function dictionary(string $locale, TranslationSettings $settings, Dictionary $dictionary): JsonResponse
    {
        abort_unless($settings->offers($locale), 404);

        return response()
            ->json(['locale' => $locale, 'version' => $settings->version(), 'strings' => (object) $dictionary->for($locale)])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Text the browser found that has no translation yet. Campaign and translation managers' reports are
     * trusted at once; anyone else's need a second visitor (see StringCollector).
     */
    public function missing(Request $request, TranslationSettings $settings, StringCollector $collector): Response
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($settings->enabled() ? $settings->languages() : [])],
            'strings' => ['required', 'array', 'max:'.StringCollector::MAX_PER_REQUEST],
            'strings.*' => ['string', 'max:'.(StringCollector::MAX_LENGTH * 2)],
        ]);

        $user = $request->user();
        $trusted = $user !== null && ($user->can('manage_translations') || $user->can('manage_campaigns'));
        $collector->record($validated['strings'], (string) $request->ip(), $trusted);

        return response()->noContent();
    }
}
