<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Models\TranslationString;
use App\Services\Ai\AiModels;
use App\Services\Translation\Languages;
use App\Services\Translation\MachineTranslator;
use App\Services\Translation\TranslationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Appearance → Languages: offer the public pages in other languages, choose the AI model that
 * translates them, and review or rewrite any translation. Routes require `manage_translations`.
 */
class LanguageController extends Controller
{
    public const STATUSES = ['all', 'missing', 'machine', 'manual', 'unconfirmed'];

    public function edit(Request $request, TranslationSettings $settings, AiModels $models, MachineTranslator $translator): Response
    {
        $languages = $settings->languages();
        $filters = $request->validate([
            'locale' => ['nullable', 'string', Rule::in($languages)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:200'],
        ]);
        $locale = $filters['locale'] ?? ($languages[0] ?? null);
        $status = $filters['status'] ?? 'all';
        $confirmed = TranslationString::query()->whereNotNull('confirmed_at')->count();

        return Inertia::render('Admin/Languages', [
            'settings' => $settings->all(),
            'languages' => Languages::options(),
            'providers' => collect($models->enabled())->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'maxDailyLimit' => TranslationSettings::MAX_DAILY_LIMIT,
            'machine' => ['available' => $translator->isAvailable(), 'remaining_today' => $translator->remainingToday()],
            'progress' => collect($languages)->map(fn (string $code) => [
                'code' => $code,
                'name' => Languages::name($code),
                'total' => $confirmed,
                'translated' => Translation::query()->where('locale', $code)
                    ->whereHas('string', fn ($query) => $query->whereNotNull('confirmed_at'))->count(),
                'manual' => Translation::query()->where('locale', $code)->where('is_manual', true)->count(),
            ]),
            'unconfirmed' => TranslationString::query()->whereNull('confirmed_at')->count(),
            'filters' => ['locale' => $locale, 'status' => $status, 'search' => $filters['search'] ?? ''],
            'strings' => $locale === null ? null : $this->strings($locale, $status, $filters['search'] ?? null),
        ]);
    }

    public function update(Request $request, TranslationSettings $settings, AiModels $models): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'languages' => ['present', 'array', 'max:'.count(Languages::ALL)],
            'languages.*' => ['string', 'distinct', Rule::in(array_keys(Languages::ALL))],
            'provider' => ['nullable', 'string', Rule::in(array_keys($models->enabled()))],
            'daily_limit' => ['required', 'integer', 'min:0', 'max:'.TranslationSettings::MAX_DAILY_LIMIT],
        ], [
            'provider.in' => 'Switch this AI model on in Admin → Integrations first.',
        ]);

        if ($validated['enabled'] && $validated['languages'] === []) {
            return back()->withErrors(['languages' => __('Choose at least one language to switch translation on.')]);
        }

        $settings->update((bool) $validated['enabled'], $validated['languages'], $validated['provider'] ?? null, (int) $validated['daily_limit']);

        return back()->with('status', $validated['enabled'] ? __('Languages saved. Visitors can now choose a language on public pages.') : __('Languages saved. Translation is off.'));
    }

    /**
     * Write (or clear) the translation of one text. A hand-written translation is never replaced by the AI model;
     * clearing it lets the AI model translate the text again.
     */
    public function saveTranslation(Request $request, TranslationString $string, TranslationSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($settings->languages())],
            'text' => ['nullable', 'string', 'max:2000'],
        ]);
        $text = trim((string) ($validated['text'] ?? ''));

        if ($text === '') {
            $string->translations()->where('locale', $validated['locale'])->delete();
        } else {
            $string->translations()->updateOrCreate(['locale' => $validated['locale']], ['text' => $text, 'is_manual' => true]);
            // An admin vouches for the text, so it counts as seen.
            $string->confirmed_at ??= now();
            $string->save();
        }

        $settings->bumpVersion();

        return back()->with('status', $text === '' ? __('Translation cleared.') : __('Translation saved.'));
    }

    /**
     * Remove a text and its translations, e.g. text that is no longer on the site. It comes back if a page still shows it.
     */
    public function destroyString(TranslationString $string, TranslationSettings $settings): RedirectResponse
    {
        $string->delete();
        $settings->bumpVersion();

        return back()->with('status', __('Text removed.'));
    }

    /**
     * Translate up to 100 missing texts straight away instead of waiting for the next scheduled run.
     */
    public function translateNow(MachineTranslator $translator): RedirectResponse
    {
        if (! $translator->isAvailable()) {
            return back()->withErrors(['provider' => __('Choose an AI model that is switched on in Admin → Integrations, and switch translation on.')]);
        }

        $saved = $translator->translatePending(100);

        return back()->with('status', $saved === 0
            ? __('Nothing was translated. Everything may be done already, today’s limit reached, or the AI model did not answer (see the log).')
            : trans_choice(':count translation saved.|:count translations saved.', $saved, ['count' => $saved]));
    }

    /**
     * @return array<string, mixed>
     */
    private function strings(string $locale, string $status, ?string $search): array
    {
        $page = TranslationString::query()
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('text', 'like', '%'.$search.'%')
                ->orWhereHas('translations', fn ($translation) => $translation->where('locale', $locale)->where('text', 'like', '%'.$search.'%'))))
            ->when($status === 'unconfirmed', fn ($query) => $query->whereNull('confirmed_at'), fn ($query) => $query->whereNotNull('confirmed_at'))
            ->when($status === 'missing', fn ($query) => $query->whereDoesntHave('translations', fn ($translation) => $translation->where('locale', $locale)))
            ->when(in_array($status, ['machine', 'manual'], true), fn ($query) => $query->whereHas('translations', fn ($translation) => $translation
                ->where('locale', $locale)->where('is_manual', $status === 'manual')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return [
            'data' => $page->getCollection()->map(fn (TranslationString $string) => [
                'id' => $string->id,
                'text' => $string->text,
                'translation' => $string->translations->first()?->text,
                'is_manual' => (bool) $string->translations->first()?->is_manual,
                'confirmed' => $string->confirmed_at !== null,
            ])->all(),
            'links' => ['prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }
}
