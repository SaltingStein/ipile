<?php

namespace App\Models;

use App\Services\Campaigns\CampaignStatus;
use App\Services\Campaigns\CampaignTemplate;
use Database\Factories\CampaignPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A campaign landing page at /c/{slug}, built in Admin → Campaigns from one of three templates. Its content is
 * a hero and a list of sections (see App\Services\Campaigns\CampaignContent).
 *
 * @property array<string, mixed> $hero
 * @property list<array<string, mixed>> $sections
 */
class CampaignPage extends Model
{
    /** @use HasFactory<CampaignPageFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'template',
        'status',
        'accent',
        'hero',
        'sections',
        'meta_description',
        'ends_at',
        'ended_message',
    ];

    protected $casts = [
        'template' => CampaignTemplate::class,
        'status' => CampaignStatus::class,
        'hero' => 'array',
        'sections' => 'array',
        'ends_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
        'accent' => 'emerald',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->status === CampaignStatus::Published;
    }

    /**
     * The end time has passed: the page now shows its "ended" message instead of buttons and the form.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    public function publicUrl(): string
    {
        return route('campaigns.show', $this->slug);
    }

    /**
     * Visitors converted per 100 views, to one decimal place.
     */
    public function conversionRate(): ?float
    {
        return $this->views === 0 ? null : round($this->conversions / $this->views * 100, 1);
    }
}
