<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blog categories (Admin → Blog) and which posts are in each. A post can be in several categories.
     */
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('blog_category_blog_post', function (Blueprint $table) {
            $table->foreignId('blog_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blog_post_id')->constrained()->cascadeOnDelete();

            $table->primary(['blog_category_id', 'blog_post_id']);
            $table->index('blog_post_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_category_blog_post');
        Schema::dropIfExists('blog_categories');
    }
};
