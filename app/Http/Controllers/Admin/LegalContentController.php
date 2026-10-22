<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Services\BrandingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LegalContentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/LegalContent', ['documents' => LegalDocument::query()->orderBy('id')->get(['slug', 'title', 'version', 'published_at', 'updated_at'])]);
    }

    public function edit(Request $request, LegalDocument $document, BrandingSettings $branding): Response
    {
        return Inertia::render('Admin/LegalEditor', [
            'document' => $document,
            'placeholders' => $branding->legalPlaceholders(),
            'history' => DB::table('legal_document_revisions')->where('legal_document_id', $document->id)->orderByDesc('id')->limit(30)->get(),
            'canEdit' => $request->user()->can('edit_legal_content'),
            'canPublish' => $request->user()->can('publish_legal_content'),
        ]);
    }

    public function update(Request $request, LegalDocument $document): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:100000'], 'revision' => ['required', 'integer', 'min:0']]);
        $document->revise($request->user(), $data['revision'], $data);

        return back()->with('status', 'Legal draft saved.');
    }

    public function publish(Request $request, LegalDocument $document): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        $document->revise($request->user(), $data['revision']);

        return back()->with('status', 'Legal page published.');
    }
}
