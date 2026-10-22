<?php

namespace App\Models;

use App\Services\BrandingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegalDocument extends Model
{
    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime', 'version' => 'integer', 'revision' => 'integer'];

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
