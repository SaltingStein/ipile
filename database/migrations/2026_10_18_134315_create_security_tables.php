<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aegis security centre: the event log, incidents, learned Markov baselines and each account's risk posture.
     */
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->string('resolution', 20)->nullable();
            $table->unsignedTinyInteger('risk_score');
            $table->string('risk_level', 20);
            $table->string('recommended_action', 30)->nullable();
            $table->string('action_taken', 30)->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamp('first_event_at');
            $table->timestamp('last_event_at');
            $table->string('ai_status', 20)->nullable();
            $table->json('ai_analysis')->nullable();
            $table->timestamp('ai_analyzed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('security_incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 100)->index();
            $table->string('category', 50);
            $table->string('ip_address', 45)->nullable();
            $table->char('device_hash', 64)->nullable();
            $table->char('network_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('context')->nullable();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->unsignedTinyInteger('posture_score')->default(0);
            $table->json('signals')->nullable();
            $table->string('recommended_action', 30)->nullable();
            $table->string('action_taken', 30)->nullable();
            $table->timestamp('occurred_at')->index();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['ip_address', 'occurred_at']);
        });

        Schema::create('security_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 100)->unique();
            $table->json('transitions');
            $table->unsignedInteger('transition_count');
            $table->timestamp('trained_at');
        });

        Schema::create('security_risk_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('belief');
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('level', 20)->default('normal');
            $table->string('last_action', 30)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the security centre tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_risk_states');
        Schema::dropIfExists('security_baselines');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_incidents');
    }
};
