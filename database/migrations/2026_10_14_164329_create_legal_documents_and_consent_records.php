<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('body');
            $table->string('published_title')->nullable();
            $table->text('published_body')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('legal_document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('version');
            $table->timestamp('created_at');
        });
        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            $table->json('choices');
            $table->json('notice_snapshot');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('legal_document_revisions');
        Schema::dropIfExists('legal_documents');
    }
};
