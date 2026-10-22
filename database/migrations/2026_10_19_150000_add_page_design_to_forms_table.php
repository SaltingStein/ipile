<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The public page's look: a layout and an optional banner image or video.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->string('layout', 20)->default('simple')->after('sends_confirmation');
            $table->string('media_type', 10)->nullable()->after('layout');
            $table->string('media_path')->nullable()->after('media_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['layout', 'media_type', 'media_path']);
        });
    }
};
