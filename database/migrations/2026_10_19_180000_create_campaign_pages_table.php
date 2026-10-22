<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campaign landing pages built in Admin → Campaigns.
     */
    public function up(): void
    {
        Schema::create('campaign_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->string('slug', 120)->unique();
            $table->string('template', 20);
            $table->string('status', 20)->default('draft');
            $table->string('accent', 20)->default('emerald');
            // The hero and the list of sections; validated by App\Services\Campaigns\CampaignContent.
            $table->json('hero');
            $table->json('sections');
            $table->string('meta_description', 300)->nullable();
            // After this time the page shows `ended_message` instead of its buttons and form.
            $table->timestamp('ends_at')->nullable();
            $table->string('ended_message', 500)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('cta_clicks')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_pages');
    }
};
