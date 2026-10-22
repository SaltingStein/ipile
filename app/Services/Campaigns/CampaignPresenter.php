<?php

namespace App\Services\Campaigns;

use App\Models\CampaignPage;
use App\Models\Form;
use App\Services\Media\MediaStorage;

/**
 * A campaign page as the browser renders it (the public page and the builder's live preview share it):
 * picture references become addresses, and the page's form comes with its questions.
 */
class CampaignPresenter
{
    public function __construct(
        private MediaStorage $storage,
        private CampaignContent $content,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function page(CampaignPage $page): array
    {
        $hero = $page->hero;
        $hero['image_url'] = $this->storage->url($hero['image'] ?? null);

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'template' => $page->template->value,
            'accent' => $page->accent,
            'meta_description' => $page->meta_description,
            'hero' => $hero,
            'sections' => array_map(fn (array $section) => ($section['type'] ?? null) === 'image'
                ? [...$section, 'image_url' => $this->storage->url($section['image'] ?? null)]
                : $section, $page->sections),
            'ends_at' => $page->ends_at?->toIso8601String(),
            'has_ended' => $page->hasEnded(),
            'ended_message' => $page->ended_message ?: __('This campaign has ended. Thank you for your interest.'),
            'form' => $this->form($this->content->formId($page->sections)),
            'cover_url' => $hero['image_url'],
        ];
    }

    /**
     * The form a page's form section shows.
     *
     * @return array<string, mixed>|null
     */
    public function form(?int $formId): ?array
    {
        $form = $formId === null ? null : Form::query()->forSite()->withCount('submissions')->find($formId);

        if ($form === null) {
            return null;
        }

        return [
            'id' => $form->id,
            'title' => $form->title,
            'fields' => $form->fields,
            'submit_label' => $form->submit_label ?: __('Submit'),
            'closed_reason' => $form->closedReason(),
            'places_left' => $form->max_submissions === null ? null : max($form->max_submissions - $form->submissions_count, 0),
            'success_message' => $form->success_message ?: __('Thank you, we have received your response.'),
        ];
    }
}
