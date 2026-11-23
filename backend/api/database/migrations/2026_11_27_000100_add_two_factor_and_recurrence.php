<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 4 : double authentification (§29.2 « MFA pour les comptes privilégiés »)
 * et tâches récurrentes (§4.2, §4.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::create('task_recurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('organization_unit_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('expected_result')->nullable();
            $table->string('task_type', 30);
            $table->string('priority', 20)->default('normal');
            $table->foreignUuid('responsible_id')->constrained('users');
            $table->jsonb('collaborator_ids')->nullable();
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->jsonb('weekdays')->nullable();
            $table->unsignedSmallInteger('day_of_month')->nullable();
            $table->time('due_time')->default('17:00');
            $table->unsignedSmallInteger('lead_days')->default(0);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_occurrence_on')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'organization_unit_id'])->references(['institution_id', 'id'])->on('organization_units');
            $table->unique(['institution_id', 'id']);
            $table->index(['is_active', 'next_occurrence_on']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('recurrence_id')->nullable()->after('recommendation_id');
            $table->date('occurrence_on')->nullable()->after('recurrence_id');
            $table->foreign(['institution_id', 'recurrence_id'])->references(['institution_id', 'id'])->on('task_recurrences');
            // Une même occurrence ne peut pas être générée deux fois.
            $table->unique(['recurrence_id', 'occurrence_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE task_recurrences ADD CONSTRAINT recurrence_frequency CHECK (frequency IN ('daily', 'weekly', 'monthly'))");
            DB::statement('ALTER TABLE task_recurrences ADD CONSTRAINT recurrence_interval CHECK (interval BETWEEN 1 AND 52)');
            DB::statement('ALTER TABLE task_recurrences ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE task_recurrences FORCE ROW LEVEL SECURITY');
            DB::statement(<<<'SQL'
                CREATE POLICY tenant_isolation ON task_recurrences
                USING (current_setting('app.bypass_rls', true) = 'on' OR institution_id::text = current_setting('app.institution_id', true))
                WITH CHECK (current_setting('app.bypass_rls', true) = 'on' OR institution_id::text = current_setting('app.institution_id', true))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['recurrence_id', 'occurrence_on']);
            $table->dropForeign(['institution_id', 'recurrence_id']);
            $table->dropColumn(['recurrence_id', 'occurrence_on']);
        });
        Schema::dropIfExists('task_recurrences');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
