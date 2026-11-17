<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Site assistant chats (CORE-911) and the append-only interaction log (CORE-913). Messages are stored after the
 * answer check, so a reply never keeps anything that looks like a key or code. All three tables are blocked for the
 * assistant's own tools (App\Services\Assistant\AssistantDataPolicy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('intent', 30)->nullable();
            $table->string('mode', 30)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('assistant_conversations')->nullOnDelete();
            $table->foreignId('user_message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('outcome', 30)->index();
            $table->string('intent', 30)->nullable();
            $table->string('mode', 30)->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('team_role', 30)->nullable();
            $table->json('roles');
            $table->json('capabilities');
            $table->json('tools');
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['user_id', 'created_at']);
        });

        // Fresh installs get the permission from RolesAndPermissionsSeeder.
        if (Role::query()->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()
                ?->givePermissionTo(Permission::findOrCreate('view_ai_activity', 'web'));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
