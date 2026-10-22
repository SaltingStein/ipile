<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Forms\PublicFormController;
use App\Models\CampaignPage;
use App\Models\Form;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignPresenter;
use App\Services\Forms\FormSchema;
use App\Services\Forms\FormSubmissionRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaign landing pages at /c/{slug} (and at / when one is chosen as the home page), open to everyone.
 * Drafts are only shown to people who manage campaigns, as a preview. Views, button clicks and form sign-ups
 * are counted for the campaign's figures in Admin → Campaigns.
 */
class CampaignController extends Controller
{
    /**
     * Session key holding the pages this visitor has already been counted on.
     */
    public const VIEWED = 'campaigns_viewed';

    public function show(Request $request, CampaignPage $campaign, CampaignPresenter $presenter): Response
    {
        return $this->render($request, $campaign, $presenter);
    }

    /**
     * Render a page; also used by the landing page when a campaign is the home page.
     */
    public function render(Request $request, CampaignPage $campaign, CampaignPresenter $presenter): Response
    {
        $canManage = $request->user()?->can('manage_campaigns') === true;
        abort_unless($campaign->isPublished() || $canManage, 404);

        if ($campaign->isPublished() && ! $canManage) {
            $this->countView($request, $campaign);
        }

        return Inertia::render('Campaigns/Show', [
            'page' => $presenter->page($campaign),
            'isPreview' => ! $campaign->isPublished(),
            'editUrl' => $canManage ? route('admin.campaigns.edit', $campaign) : null,
            'submitUrl' => route('campaigns.submit', $campaign->slug),
            'clickUrl' => route('campaigns.click', $campaign->slug),
            'submitted' => $request->session()->get('campaign_submitted') === $campaign->id,
            'honeypot' => PublicFormController::HONEYPOT,
        ]);
    }

    /**
     * Answers to the page's form. They are saved with the form's other responses and count as a sign-up.
     */
    public function submit(Request $request, CampaignPage $campaign, CampaignContent $content, FormSchema $schema, FormSubmissionRecorder $recorder): RedirectResponse
    {
        abort_unless($campaign->isPublished(), 404);
        $form = Form::query()->forSite()->find($content->formId($campaign->sections));
        abort_if($form === null, 404);

        if ($campaign->hasEnded()) {
            throw ValidationException::withMessages(['form' => __('This campaign has ended and no longer accepts sign-ups.')]);
        }

        if (filled($request->input(PublicFormController::HONEYPOT))) {
            return back()->with('campaign_submitted', $campaign->id);
        }

        $validated = $request->validate($schema->rules($form->fields), [], $schema->attributes($form->fields));
        $recorder->record($form, $validated['answers'] ?? [], $request->user());
        CampaignPage::query()->whereKey($campaign->id)->toBase()->increment('conversions');

        return back()->with('campaign_submitted', $campaign->id);
    }

    /**
     * A visitor chose one of the page's buttons (sent by the browser in the background).
     */
    public function click(CampaignPage $campaign): HttpResponse
    {
        if ($campaign->isPublished() && ! $campaign->hasEnded()) {
            CampaignPage::query()->whereKey($campaign->id)->toBase()->increment('cta_clicks');
        }

        return response()->noContent();
    }

    /**
     * Count each visitor once per browser session.
     */
    private function countView(Request $request, CampaignPage $campaign): void
    {
        $viewed = (array) $request->session()->get(self::VIEWED, []);

        if (in_array($campaign->id, $viewed, true)) {
            return;
        }

        // Counted without touching updated_at, which shows when the page was last edited.
        CampaignPage::query()->whereKey($campaign->id)->toBase()->increment('views');
        $request->session()->put(self::VIEWED, [...array_slice($viewed, -49), $campaign->id]);
    }
}
