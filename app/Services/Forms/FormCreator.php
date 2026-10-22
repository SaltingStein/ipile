<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Creates draft forms from templates or saved forms, for a team or (with no team) for the site.
 */
class FormCreator
{
    public function __construct(private FormTemplates $templates, private FormMedia $media) {}

    public function create(?Team $team, User $creator, string $templateKey): Form
    {
        $template = $this->templates->find($templateKey)
            ?? throw new InvalidArgumentException("Unknown form template [{$templateKey}].");

        $form = new Form([
            'title' => $template['title'],
            'description' => $template['form_description'],
            'status' => FormStatus::Draft,
            'fields' => $template['fields'],
            'slug' => $this->uniqueSlug($template['title']),
        ]);
        $form->team()->associate($team);
        $form->creator()->associate($creator);
        $form->save();

        return $form;
    }

    /**
     * Copy the saved content into an ordinary draft in the same scope, with its own address and banner.
     * Responses, the registration purpose and the previous closing time never carry over.
     */
    public function duplicate(Form $source, User $creator): Form
    {
        $copy = new Form($source->only([
            'description', 'fields', 'submit_label', 'success_message',
            'max_submissions', 'sends_confirmation', 'layout',
        ]));
        $copy->title = Str::limit(__('Copy of :title', ['title' => $source->title]), 150, '');
        $copy->slug = $this->uniqueSlug($copy->title);
        $copy->team()->associate($source->team_id);
        $copy->creator()->associate($creator);

        try {
            return DB::transaction(function () use ($source, $copy): Form {
                $copy->save();
                $this->media->copyTo($source, $copy);

                return $copy;
            });
        } catch (Throwable $exception) {
            $this->media->deleteFile($copy->media_path);

            throw $exception;
        }
    }

    /**
     * A readable address with a short random suffix, so new forms never clash and are hard to guess before publishing.
     */
    public function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'form', 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Form::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
