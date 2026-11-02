<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables whose rows are soft deleted (SDGP: soft deletes by default).
     *
     * Not here, on purpose: `teams` and `blog_categories` rely on database
     * cascades that a soft delete would silently skip; they need their own design.
     *
     * @var list<string>
     */
    private array $tables = ['users', 'payments', 'blog_posts', 'campaign_pages', 'forms', 'form_submissions'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->softDeletes()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_deleted_at_index');
                $table->dropSoftDeletes();
            });
        }
    }
};
