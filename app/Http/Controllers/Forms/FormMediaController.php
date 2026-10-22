<?php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\Forms\FormMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Upload or remove the banner image or video on a form's public page. Saved straight away, separately from
 * the builder's Save button. The sign-up form has no page of its own, so it has no banner.
 */
class FormMediaController extends Controller
{
    public function store(Request $request, Form $form, FormMedia $media): RedirectResponse
    {
        Gate::authorize('update', $form);
        abort_if($form->isRegistrationForm(), 404);

        $limits = $media->limits();
        $request->validate([
            'media' => [
                'required', 'file',
                'mimes:'.implode(',', [...FormMedia::IMAGE_TYPES, ...FormMedia::VIDEO_TYPES]),
                'max:'.max($limits['image_kb'], $limits['video_kb']),
            ],
        ], [
            'media.mimes' => 'Upload a JPG, PNG or WebP image, or an MP4 or WebM video.',
            'media.uploaded' => 'The file is larger than this server accepts. Try a smaller file.',
        ]);

        $media->replace($form, $request->file('media'));

        return back()->with('status', __('Banner uploaded.'));
    }

    public function destroy(Form $form, FormMedia $media): RedirectResponse
    {
        Gate::authorize('update', $form);

        $media->remove($form);

        return back()->with('status', __('Banner removed.'));
    }
}
