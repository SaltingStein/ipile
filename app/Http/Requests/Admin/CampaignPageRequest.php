<?php

namespace App\Http\Requests\Admin;

use App\Models\CampaignPage;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignStatus;
use App\Services\Campaigns\CampaignTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A campaign page from the Admin → Campaigns builder. Access is checked by the route's `permission:manage_campaigns`.
 */
class CampaignPageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $sections = $this->input('sections');

        return [
            'title' => ['required', 'string', 'max:150'],
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(CampaignPage::class, 'slug')->ignore($this->route('campaign')),
            ],
            'template' => ['required', Rule::enum(CampaignTemplate::class)],
            'status' => ['required', Rule::enum(CampaignStatus::class)],
            'accent' => ['required', Rule::in(CampaignContent::ACCENTS)],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'ends_at' => ['nullable', 'date'],
            'ended_message' => ['nullable', 'string', 'max:500'],
            ...app(CampaignContent::class)->rules(is_array($sections) ? $sections : []),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => app(CampaignContent::class)->after($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes, for example summer-sale.',
            'slug.unique' => 'Another campaign page already uses this address.',
            'hero.headline.required' => 'Add a headline.',
            'hero.image.required_if' => 'Upload a banner picture, or choose no banner.',
            'hero.video_url.required_if' => 'Paste a YouTube or Vimeo link, or choose no banner.',
            'sections.*.form_id.exists' => 'Choose a published site form.',
            'regex' => 'Check the format of this value.',
            'hero.buttons.*.url.regex' => 'Use a web address (https://…), a page on this site (/…), a section on this page (#…), or a mailto: or tel: link.',
            'sections.*.buttons.*.url.regex' => 'Use a web address (https://…), a page on this site (/…), a section on this page (#…), or a mailto: or tel: link.',
        ];
    }

    /**
     * The page's columns, with the hero and sections reduced to their known keys.
     *
     * @return array<string, mixed>
     */
    public function pageAttributes(): array
    {
        $content = app(CampaignContent::class)->normalise((array) $this->validated('hero'), (array) $this->validated('sections'));

        return [
            'title' => $this->validated('title'),
            'slug' => $this->validated('slug'),
            'template' => $this->validated('template'),
            'status' => $this->validated('status'),
            'accent' => $this->validated('accent'),
            'meta_description' => $this->validated('meta_description'),
            'ends_at' => $this->validated('ends_at'),
            'ended_message' => $this->validated('ended_message'),
            'hero' => $content['hero'],
            'sections' => $content['sections'],
        ];
    }
}
