<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use App\Services\BrandingSettings;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class LegalPageController extends Controller
{
    public function show(LegalDocument $document): Response
    {
        abort_if($document->version === 0, 404);

        return Inertia::render('LegalPage', ['document' => $this->published($document)]);
    }

    public function indexApi(): JsonResponse
    {
        return response()->json(['data' => LegalDocument::query()->where('version', '>', 0)->get()->map(fn ($document) => $this->published($document))]);
    }

    public function showApi(LegalDocument $document): JsonResponse
    {
        abort_if($document->version === 0, 404);

        return response()->json(['data' => $this->published($document)]);
    }

    /**
     * @return array{slug: string, title: ?string, body: ?string, version: int, published_at: ?string}
     */
    private function published(LegalDocument $document): array
    {
        $branding = app(BrandingSettings::class);

        return ['slug' => $document->slug, 'title' => $branding->fillLegalPlaceholders($document->published_title), 'body' => $branding->fillLegalPlaceholders($document->published_body), 'version' => $document->version, 'published_at' => $document->published_at?->toIso8601String()];
    }
}
