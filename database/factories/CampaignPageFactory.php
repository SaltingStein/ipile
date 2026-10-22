<?php

namespace Database\Factories;

use App\Models\CampaignPage;
use App\Models\User;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignStatus;
use App\Services\Campaigns\CampaignTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A published Spotlight page with its starter content.
 *
 * @extends Factory<CampaignPage>
 */
class CampaignPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->words(3, true));
        $starter = CampaignTemplate::Spotlight->starter($title);
        $content = app(CampaignContent::class)->normalise($starter['hero'], $starter['sections']);

        return [
            'created_by' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'template' => CampaignTemplate::Spotlight,
            'status' => CampaignStatus::Published,
            'accent' => 'emerald',
            'hero' => $content['hero'],
            'sections' => $content['sections'],
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => CampaignStatus::Draft]);
    }

    public function ended(): static
    {
        return $this->state(['ends_at' => now()->subDay(), 'ended_message' => 'This campaign has ended. Thank you!']);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    public function withSections(array $sections): static
    {
        return $this->state(['sections' => $sections]);
    }
}
