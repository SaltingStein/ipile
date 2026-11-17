<?php

use App\Models\LegalDocument;
use Illuminate\Database\Migrations\Migration;

/**
 * The starter Privacy Policy, Terms and Conditions and Cookie Policy used to exist only after running
 * LegalContentSeeder, so deployed environments had none. Every install now gets them as unpublished drafts on migrate.
 * Documents that already exist are left exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        LegalDocument::createMissingDefaultDrafts();
    }

    public function down(): void
    {
        // Only drafts nobody has saved or published are removed; anything an editor touched is kept.
        LegalDocument::query()
            ->whereIn('slug', array_keys(LegalDocument::DEFAULT_DRAFTS))
            ->where('revision', 0)
            ->where('version', 0)
            ->delete();
    }
};
