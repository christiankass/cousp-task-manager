<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3 – Domaines E et F : événements sanitaires, missions opérationnelles,
 * réunions et recommandations, et leur rattachement aux tâches.
 *
 * Les tâches restent le moteur commun : une recommandation se convertit en tâches,
 * une mission s'appuie sur une tâche opérationnelle (et donc sur son circuit de validation).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Numérotation séparée par type de dossier : COUSP-EVT-2026-001, COUSP-REU-2026-001…
        Schema::create('reference_counters', function (Blueprint $table) {
            $table->uuid('institution_id');
            $table->string('scope', 20);
            $table->smallInteger('year');
            $table->unsignedInteger('last_value')->default(0);
            $table->primary(['institution_id', 'scope', 'year']);
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
        });

        Schema::create('health_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->string('reference', 40);
            $table->string('title', 255);
            $table->string('event_type', 40);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('severity', 20)->default('moderate');
            $table->string('status', 20)->default('active');
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->text('closure_note')->nullable();
            $table->foreignUuid('coordinator_id')->nullable()->constrained('users');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->unique(['institution_id', 'reference']);
            $table->unique(['institution_id', 'id']);
            $table->index(['institution_id', 'status']);
        });

        Schema::create('health_event_situation_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('health_event_id');
            $table->date('report_date');
            $table->text('summary');
            $table->text('actions')->nullable();
            $table->text('needs')->nullable();
            $table->foreignUuid('author_id')->constrained('users');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'health_event_id'])->references(['institution_id', 'id'])->on('health_events')->cascadeOnDelete();
            $table->index(['health_event_id', 'report_date']);
        });

        Schema::create('meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('organization_unit_id')->nullable();
            $table->uuid('health_event_id')->nullable();
            $table->string('reference', 40);
            $table->string('title', 255);
            $table->string('meeting_type', 30)->default('coordination');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->text('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->string('status', 20)->default('planned');
            $table->foreignUuid('chair_id')->nullable()->constrained('users');
            $table->foreignUuid('secretary_id')->nullable()->constrained('users');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'organization_unit_id'])->references(['institution_id', 'id'])->on('organization_units');
            $table->foreign(['institution_id', 'health_event_id'])->references(['institution_id', 'id'])->on('health_events');
            $table->unique(['institution_id', 'reference']);
            $table->unique(['institution_id', 'id']);
            $table->index(['institution_id', 'starts_at']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('meeting_id');
            $table->foreignUuid('user_id')->nullable()->constrained();
            $table->string('external_name', 190)->nullable();
            $table->string('organization', 190)->nullable();
            $table->string('attendance', 20)->default('invited');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'meeting_id'])->references(['institution_id', 'id'])->on('meetings')->cascadeOnDelete();
            $table->unique(['meeting_id', 'user_id']);
        });

        Schema::create('recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('meeting_id');
            $table->uuid('organization_unit_id')->nullable();
            $table->unsignedSmallInteger('number');
            $table->string('reference', 50);
            $table->text('content');
            $table->text('expected_result')->nullable();
            $table->foreignUuid('responsible_id')->nullable()->constrained('users');
            $table->timestamp('due_at')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('open');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'meeting_id'])->references(['institution_id', 'id'])->on('meetings')->cascadeOnDelete();
            $table->foreign(['institution_id', 'organization_unit_id'])->references(['institution_id', 'id'])->on('organization_units');
            $table->unique(['meeting_id', 'number']);
            $table->unique(['institution_id', 'reference']);
            $table->unique(['institution_id', 'id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('health_event_id')->nullable()->after('parent_task_id');
            $table->uuid('recommendation_id')->nullable()->after('health_event_id');
            $table->foreign(['institution_id', 'health_event_id'])->references(['institution_id', 'id'])->on('health_events');
            $table->foreign(['institution_id', 'recommendation_id'])->references(['institution_id', 'id'])->on('recommendations');
            $table->index(['institution_id', 'health_event_id']);
            $table->index(['institution_id', 'recommendation_id']);
        });

        Schema::create('operational_missions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('health_event_id')->nullable();
            $table->uuid('task_id');
            $table->string('reference', 40);
            $table->string('title', 255);
            $table->string('destination', 255);
            $table->text('objectives');
            $table->timestamp('departure_at');
            $table->timestamp('return_at');
            $table->foreignUuid('mission_leader_id')->constrained('users');
            $table->text('report')->nullable();
            $table->timestamp('report_submitted_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'health_event_id'])->references(['institution_id', 'id'])->on('health_events');
            $table->foreign(['institution_id', 'task_id'])->references(['institution_id', 'id'])->on('tasks');
            $table->unique(['institution_id', 'reference']);
            $table->unique(['institution_id', 'id']);
            $table->unique('task_id');
        });

        Schema::create('mission_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('mission_id');
            $table->foreignUuid('user_id')->constrained();
            $table->string('assignment', 120)->nullable();
            $table->string('participation_status', 20)->default('assigned');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'mission_id'])->references(['institution_id', 'id'])->on('operational_missions')->cascadeOnDelete();
            $table->unique(['mission_id', 'user_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE health_events ADD CONSTRAINT health_events_dates CHECK (end_at IS NULL OR end_at >= start_at)');
            DB::statement('ALTER TABLE operational_missions ADD CONSTRAINT missions_dates CHECK (return_at >= departure_at)');
            DB::statement('ALTER TABLE meeting_participants ADD CONSTRAINT participant_identity CHECK (user_id IS NOT NULL OR external_name IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_members');
        Schema::dropIfExists('operational_missions');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['institution_id', 'health_event_id']);
            $table->dropForeign(['institution_id', 'recommendation_id']);
            $table->dropIndex(['institution_id', 'health_event_id']);
            $table->dropIndex(['institution_id', 'recommendation_id']);
            $table->dropColumn(['health_event_id', 'recommendation_id']);
        });
        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('health_event_situation_reports');
        Schema::dropIfExists('health_events');
        Schema::dropIfExists('reference_counters');
    }
};
