<?php

namespace App\Services\Campaigns;

use App\Models\CampaignPage;
use App\Services\Media\MediaStorage;

/**
 * Pictures that belong to campaign pages (the hero banner and picture sections), saved under `campaigns/` on
 * this server or in the storage bucket. A picture is deleted once no page uses it, including pictures that were
 * uploaded in the builder but never saved.
 */
class CampaignAssets
{
    public function __construct(private MediaStorage $storage, private CampaignContent $content) {}

    /**
     * After a page is saved: delete pictures its old content used that the new content and other pages do not.
     *
     * @param  array<string, mixed>|null  $oldHero
     * @param  array<mixed>|null  $oldSections
     */
    public function pruneRemoved(CampaignPage $page, ?array $oldHero, ?array $oldSections): void
    {
        $removed = array_diff(
            $this->content->imageReferences($oldHero, $oldSections),
            $this->content->imageReferences($page->hero, $page->sections),
        );

        $this->deleteUnused($removed, $page);
    }

    /**
     * Delete every picture of a deleted page that no other page uses.
     */
    public function deleteFor(CampaignPage $page): void
    {
        $this->deleteUnused($this->content->imageReferences($page->hero, $page->sections), $page);
    }

    /**
     * Delete pictures uploaded more than `$olderThanHours` ago that no page uses.
     *
     * @return int The number of files deleted.
     */
    public function pruneOrphans(int $olderThanHours = 24): int
    {
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();

        $orphans = array_keys(array_filter($this->storage->filesIn(CampaignContent::IMAGE_DIRECTORY), fn (int $modified, string $reference) => $modified < $cutoff
            && ! $this->isUsed($reference), ARRAY_FILTER_USE_BOTH));

        if ($orphans !== []) {
            $this->storage->delete($orphans);
        }

        return count($orphans);
    }

    /**
     * @param  array<string>  $references
     */
    private function deleteUnused(array $references, CampaignPage $page): void
    {
        $unused = array_values(array_filter($references, fn (string $reference) => ! $this->isUsed($reference, $page)));

        if ($unused !== []) {
            $this->storage->delete($unused);
        }
    }

    /**
     * Uploaded file names are random, so a page whose content contains the name uses that file.
     */
    private function isUsed(string $reference, ?CampaignPage $except = null): bool
    {
        $name = basename($reference);

        return CampaignPage::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->where(fn ($query) => $query->where('hero', 'like', "%{$name}%")->orWhere('sections', 'like', "%{$name}%"))
            ->exists();
    }
}
