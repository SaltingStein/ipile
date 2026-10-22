<?php

namespace App\Services\Forms;

use App\Models\Form;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The banner image or video on a form's public page, stored on the public disk under forms/{id}.
 */
class FormMedia
{
    public const DISK = 'public';

    public const IMAGE_MAX_KB = 5 * 1024;

    public const VIDEO_MAX_KB = 50 * 1024;

    public const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    public const VIDEO_TYPES = ['mp4', 'webm'];

    /**
     * Upload limits in kilobytes, never above what the server's PHP settings accept
     * (upload_max_filesize and post_max_size), so the builder can warn before uploading.
     *
     * @return array{image_kb: int, video_kb: int}
     */
    public function limits(): array
    {
        $serverMaxKb = (int) floor(UploadedFile::getMaxFilesize() / 1024);

        return [
            'image_kb' => min(self::IMAGE_MAX_KB, $serverMaxKb),
            'video_kb' => min(self::VIDEO_MAX_KB, $serverMaxKb),
        ];
    }

    /**
     * Store the file as the form's banner, replacing (and deleting) any previous one.
     *
     * @throws ValidationException when an image or video is over its own limit, or storing fails
     */
    public function replace(Form $form, UploadedFile $file): void
    {
        $type = in_array(strtolower($file->guessExtension() ?? ''), self::VIDEO_TYPES, true) ? 'video' : 'image';
        $limitKb = $this->limits()[$type.'_kb'];

        if ($file->getSize() > $limitKb * 1024) {
            throw ValidationException::withMessages(['media' => __('The :type must not be larger than :size MB.', [
                'type' => $type === 'video' ? __('video') : __('image'),
                'size' => round($limitKb / 1024, 1),
            ])]);
        }

        $path = $file->store('forms/'.$form->getKey(), self::DISK)
            ?: throw ValidationException::withMessages(['media' => __('The file could not be stored. Please try again.')]);

        $oldPath = $form->media_path;
        $form->forceFill(['media_type' => $type, 'media_path' => $path])->save();
        $this->deleteFile($oldPath);
    }

    /**
     * Give a saved draft its own banner. The creator rolls back the draft and removes this path on failure.
     */
    public function copyTo(Form $source, Form $copy): void
    {
        if ($source->media_path === null) {
            return;
        }

        $path = 'forms/'.$copy->getKey().'/'.Str::uuid().'.'.pathinfo($source->media_path, PATHINFO_EXTENSION);
        $copy->forceFill(['media_type' => $source->media_type, 'media_path' => $path]);

        if (! Storage::disk(self::DISK)->copy($source->media_path, $path)) {
            throw ValidationException::withMessages(['duplicate' => __('The banner could not be copied. Please try again.')]);
        }

        $copy->save();
    }

    public function remove(Form $form): void
    {
        $oldPath = $form->media_path;
        $form->forceFill(['media_type' => null, 'media_path' => null])->save();
        $this->deleteFile($oldPath);
    }

    /**
     * @return array{type: string, url: string}|null
     */
    public function details(Form $form): ?array
    {
        return $form->media_path === null ? null : [
            'type' => $form->media_type,
            'url' => Storage::disk(self::DISK)->url($form->media_path),
        ];
    }

    public function deleteFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
