<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Text found on public pages (in the site's own language) and its translations (Admin → Languages).
     */
    public function up(): void
    {
        Schema::create('translation_strings', function (Blueprint $table) {
            $table->id();
            // sha256 of the text, so long texts can be unique.
            $table->char('hash', 64)->unique();
            $table->text('text');
            // Who reported it first (a keyed hash of the IP address, never the address itself). The text is only
            // machine-translated once a second visitor has seen it too (`confirmed_at`), so text sent by one
            // person cannot cost AI credit or slip into translations.
            $table->char('reporter_hash', 64);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('confirmed_at');
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_string_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->text('text');
            // Typed by an admin; never replaced by machine translation.
            $table->boolean('is_manual')->default(false);
            $table->timestamps();

            $table->unique(['translation_string_id', 'locale']);
            $table->index('locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translations');
        Schema::dropIfExists('translation_strings');
    }
};
