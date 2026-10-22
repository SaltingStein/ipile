<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forms built in the drag-and-drop builder and the answers people send.
     */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            // Null for site forms (managed in the admin area); set for a team's own forms.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // A built-in use such as "registration" (the extra sign-up questions). Null for ordinary forms.
            $table->string('purpose', 40)->nullable()->unique();
            $table->string('title', 150);
            // Public address /f/{slug}. Null for forms that are not opened on their own (the sign-up form).
            $table->string('slug', 80)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('fields');
            $table->string('submit_label', 60)->nullable();
            $table->text('success_message')->nullable();
            $table->unsignedInteger('max_submissions')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->boolean('sends_confirmation')->default(false);
            $table->timestamps();

            $table->index(['team_id', 'updated_at']);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data');
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
    }
};
