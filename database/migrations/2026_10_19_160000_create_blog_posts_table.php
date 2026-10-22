<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blog posts written in Admin → Blog.
     */
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 120)->unique();
            $table->string('excerpt', 300)->nullable();
            // Markdown; raw HTML is stripped when it is shown.
            $table->longText('body');
            $table->string('cover_image_path')->nullable();
            $table->string('status', 20)->default('draft');
            // A future time schedules the post: it stays hidden until then.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
