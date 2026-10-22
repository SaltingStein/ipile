<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampaignPageRequest;
use App\Models\CampaignPage;
use App\Models\Form;
use App\Services\Campaigns\CampaignAssets;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignPresenter;
use App\Services\Campaigns\CampaignSettings;
use App\Services\Campaigns\CampaignStatus;
use App\Services\Campaigns\CampaignTemplate;
use App\Services\Forms\FormStatus;
use App\Services\Media\MediaStorage;
use App\Services\NavigationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Campaigns: landing pages built from a template. Routes require `manage_campaigns`.
 */
class CampaignPageController extends Controller
{
    public const IMAGE_MAX_KB = 5 * 1024;

    public function index(CampaignSettings $settings): Response
    {
        $homePageId = $settings->homePageId();

        return Inertia::render('Admin/Campaigns/Index', [
            'pages' => CampaignPage::query()->latest('updated_at')->get()->map(fn (CampaignPage $page) => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'template' => $page->template->label(),
                'status' => $page->status->value,
                'has_ended' => $page->hasEnded(),
                'ends_at' => $page->ends_at?->toIso8601String(),
                'is_home_page' => $page->id === $homePageId,
                'url' => $page->publicUrl(),
                'views' => $page->views,
                'cta_clicks' => $page->cta_clicks,
                'conversions' => $page->conversions,
                'conversion_rate' => $page->conversionRate(),
                'updated_at' => $page->updated_at?->toIso8601String(),
            ]),
            'templates' => CampaignTemplate::options(),
        ]);
    }

    /**
     * Start a draft from a template's starter content and open the builder.
     */
    public function store(Request $request, CampaignContent $content): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'template' => ['required', Rule::enum(CampaignTemplate::class)],
        ]);

        $template = CampaignTemplate::from($validated['template']);
        $starter = $template->starter($validated['title']);
        $page = $this->create($request, [
            'title' => $validated['title'],
            'template' => $template,
            ...$content->normalise($starter['hero'], $starter['sections']),
        ]);

        return redirect()->route('admin.campaigns.edit', $page)->with('status', __('Draft created. Replace the starter text, then publish.'));
    }

    public function edit(Request $request, CampaignPage $campaign, CampaignPresenter $presenter, CampaignSettings $settings, NavigationSettings $navigation): Response
    {
        $path = '/c/'.$campaign->slug;

        return Inertia::render('Admin/Campaigns/Builder', [
            'campaign' => [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'slug' => $campaign->slug,
                'template' => $campaign->template->value,
                'status' => $campaign->status->value,
                'accent' => $campaign->accent,
                'meta_description' => $campaign->meta_description,
                'ends_at' => $campaign->ends_at?->toIso8601String(),
                'ended_message' => $campaign->ended_message,
                'hero' => $campaign->hero,
                'sections' => $campaign->sections,
                'url' => $campaign->publicUrl(),
                'is_home_page' => $settings->homePageId() === $campaign->id,
                'stats' => ['views' => $campaign->views, 'cta_clicks' => $campaign->cta_clicks, 'conversions' => $campaign->conversions],
            ],
            // Addresses of the pictures the page uses, for the live preview.
            'imageUrls' => collect(app(CampaignContent::class)->imageReferences($campaign->hero, $campaign->sections))
                ->mapWithKeys(fn (string $reference) => [$reference => app(MediaStorage::class)->url($reference)]),
            'forms' => Form::query()->forSite()->whereNull('purpose')->where('status', '!=', FormStatus::Draft)->orderBy('title')->get()
                ->map(fn (Form $form) => $presenter->form($form->id)),
            'templates' => CampaignTemplate::options(),
            'accents' => CampaignContent::ACCENTS,
            'sectionTypes' => CampaignContent::SECTION_TYPES,
            'maxSections' => CampaignContent::MAX_SECTIONS,
            'imageLimitKb' => $this->imageLimitKb(),
            // Whether the page is already in each menu; null when this person cannot change menus.
            'menus' => $request->user()->can('manage_settings') ? [
                'path' => $path,
                'site' => $navigation->contains(NavigationSettings::MENU_SITE, $path),
                'workspace' => $navigation->contains(NavigationSettings::MENU_WORKSPACE, $path),
            ] : null,
        ]);
    }

    public function update(CampaignPageRequest $request, CampaignPage $campaign, CampaignAssets $assets): RedirectResponse
    {
        $oldHero = $campaign->hero;
        $oldSections = $campaign->sections;
        $wasPublished = $campaign->isPublished();

        $campaign->update($request->pageAttributes());
        $assets->pruneRemoved($campaign, $oldHero, $oldSections);

        return back()->with('status', match (true) {
            $campaign->isPublished() && ! $wasPublished => __('Published. The page is live at :url.', ['url' => $campaign->publicUrl()]),
            $campaign->isPublished() => __('Saved. Changes are live.'),
            default => __('Draft saved.'),
        });
    }

    /**
     * Copy a page as a new draft, e.g. to reuse a design for the next campaign. Pictures are shared, and kept
     * until neither page uses them.
     */
    public function duplicate(Request $request, CampaignPage $campaign): RedirectResponse
    {
        $copy = $this->create($request, [
            'title' => Str::limit(__('Copy of :title', ['title' => $campaign->title]), 150, ''),
            'template' => $campaign->template,
            'accent' => $campaign->accent,
            'hero' => $campaign->hero,
            'sections' => $campaign->sections,
            'meta_description' => $campaign->meta_description,
            'ended_message' => $campaign->ended_message,
        ]);

        return redirect()->route('admin.campaigns.edit', $copy)->with('status', __('Copied as a new draft.'));
    }

    public function destroy(CampaignPage $campaign, CampaignAssets $assets, CampaignSettings $settings): RedirectResponse
    {
        $campaign->delete();
        $assets->deleteFor($campaign);

        if ($settings->homePageId() === $campaign->id) {
            $settings->setHomePage(null);
        }

        return redirect()->route('admin.campaigns.index')->with('status', __('Campaign page deleted.'));
    }

    /**
     * Show this page at / instead of the landing page, or go back to the landing page.
     */
    public function homePage(Request $request, CampaignPage $campaign, CampaignSettings $settings): RedirectResponse
    {
        $validated = $request->validate(['is_home_page' => ['required', 'boolean']]);

        if (! $validated['is_home_page']) {
            if ($settings->homePageId() === $campaign->id) {
                $settings->setHomePage(null);
            }

            return back()->with('status', __('The landing page is the home page again.'));
        }

        if (! $campaign->isPublished() || $campaign->hasEnded()) {
            throw ValidationException::withMessages(['is_home_page' => __('Publish the page (and check it has not ended) before making it the home page.')]);
        }

        $settings->setHomePage($campaign);

        return back()->with('status', __('“:title” is now the home page.', ['title' => $campaign->title]));
    }

    /**
     * Upload a banner or a picture for a section and return its reference and address. The page itself is
     * saved with the rest of the builder.
     */
    public function uploadImage(Request $request, MediaStorage $storage): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.$this->imageLimitKb(), 'dimensions:max_width=8000,max_height=8000'],
        ], [
            'image.uploaded' => 'The image is larger than this server accepts. Try a smaller file.',
        ]);

        $reference = $storage->store($request->file('image'), CampaignContent::IMAGE_DIRECTORY, 'image');

        return response()->json(['reference' => $reference, 'url' => $storage->url($reference)], 201);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(Request $request, array $attributes): CampaignPage
    {
        $page = new CampaignPage([
            ...$attributes,
            'slug' => $this->uniqueSlug((string) $attributes['title']),
            'status' => CampaignStatus::Draft,
        ]);
        $page->creator()->associate($request->user());
        $page->save();

        return $page;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 100, '') ?: 'campaign';
        $slug = $base;

        for ($number = 2; CampaignPage::query()->where('slug', $slug)->exists(); $number++) {
            $slug = "{$base}-{$number}";
        }

        return $slug;
    }

    private function imageLimitKb(): int
    {
        return min(self::IMAGE_MAX_KB, (int) floor(UploadedFile::getMaxFilesize() / 1024));
    }
}
