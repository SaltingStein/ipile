<?php

namespace App\Models;

use App\Services\BrandingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegalDocument extends Model
{
    protected $guarded = [];

    /**
     * Starter templates every install gets as unpublished drafts, keyed by slug.
     *
     * @var array<string, array{title: string, body: string}>
     */
    public const DEFAULT_DRAFTS = [
        'privacy' => ['title' => 'Privacy Policy', 'body' => "About this policy\n[Organisation name] operates [service name]. Contact our privacy team at [contact email]. The bracketed names are filled in from Admin → Branding; review this draft for your product before publishing.\n\nInformation we process\nDescribe account details, transactions, device information and any other information collected by your service.\n\nPurposes and legal grounds\nExplain why each category is processed and the applicable legal basis.\n\nSharing, retention and transfers\nIdentify recipients, retention periods and any international transfer arrangements.\n\nYour choices and rights\nExplain applicable access, correction, deletion, objection and complaint procedures. Use Cookie preferences to change optional cookie choices.\n\nUpdates\nPublished versions and the last-updated date appear on this page."],
        'terms' => ['title' => 'Terms and Conditions', 'body' => "About this service\nThese terms govern [service name], provided by [organisation name]. The bracketed names are filled in from Admin → Branding; review this draft before publishing.\n\nUsing the service\nDescribe eligibility, acceptable use and account responsibilities.\n\nCharges and cancellations\nSpecify pricing, payment conditions, cancellation and refund arrangements where applicable.\n\nContent and intellectual property\nDescribe ownership, licences and user-submitted content.\n\nAvailability, liability and termination\nSpecify service availability, liability provisions, suspension and termination in terms suitable for your product.\n\nDisputes and contact\nIdentify the applicable governing law, dispute process and contact details."],
        'cookies' => ['title' => 'Cookie Policy', 'body' => "About cookies\nCookies help a website remember information between visits. Review this draft and list the cookies actually used by your product before publishing.\n\nNecessary cookies\nOur application uses cookies for sign-in, request protection and remembering your privacy preferences.\n\nOptional cookies\nDescribe any analytics and marketing cookies, their providers, purposes and retention periods. Optional features should run only when the corresponding preference is enabled.\n\nChanging your choice\nOpen Cookie preferences in the footer to accept, reject or change optional categories. Changes apply on the next page load."],
    ];

    protected $casts = ['published_at' => 'datetime', 'version' => 'integer', 'revision' => 'integer'];

    /**
     * Adds any missing starter document as an unpublished draft. Existing documents, edited or not, are left alone.
     */
    public static function createMissingDefaultDrafts(): void
    {
        foreach (self::DEFAULT_DRAFTS as $slug => $draft) {
            self::query()->firstOrCreate(['slug' => $slug], $draft);
        }
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function revise(User $actor, int $expectedRevision, ?array $draft = null): void
    {
        DB::transaction(function () use ($actor, $expectedRevision, $draft): void {
            $document = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($document->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['document' => 'This document has changed. Reload before saving or publishing.']);
            }

            if ($draft !== null) {
                $document->fill(['title' => $draft['title'], 'body' => $draft['body']]);
            } else {
                $document->published_title = $document->title;
                $document->published_body = $document->body;
                $document->version++;
                $document->published_at = now();
            }

            $document->revision++;
            $document->save();
            DB::table('legal_document_revisions')->insert([
                'legal_document_id' => $document->id,
                'user_id' => $actor->id,
                'action' => $draft === null ? 'published' : 'draft_saved',
                'title' => $document->title,
                'body' => $document->body,
                'version' => $document->version,
                'created_at' => now(),
            ]);
        });
    }

    public static function publicLinks(): array
    {
        $branding = app(BrandingSettings::class);

        return self::query()->where('version', '>', 0)->orderBy('id')->get()
            ->map(fn (self $document) => ['title' => $branding->fillLegalPlaceholders($document->published_title), 'href' => route('legal.show', $document)])
            ->all();
    }
}
