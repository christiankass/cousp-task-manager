<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine D : validation hiérarchique. La configuration (templates) est séparée
 * de l'exécution (workflows, steps). Les décisions ne sont jamais modifiées : une
 * nouvelle soumission ouvre un nouveau workflow.
 *
 * Types d'approbateur d'une étape :
 *  - unit_manager        : responsable de la structure de la tâche (ou de la plus proche structure parente qui en a un) ;
 *  - parent_unit_manager : responsable de la structure parente ;
 *  - role                : toute personne ayant ce rôle sur la structure (ou une parente) ou sur l'institution ;
 *  - user                : une personne désignée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->string('name', 150);
            $table->string('task_type', 30)->nullable();
            $table->uuid('organization_unit_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'organization_unit_id'])->references(['institution_id', 'id'])->on('organization_units');
            $table->unique(['institution_id', 'id']);
        });

        Schema::create('approval_template_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('approval_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('approver_type', 30);
            $table->foreignUuid('approver_role_id')->nullable()->constrained('roles');
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users');
            $table->boolean('is_required')->default(true);
            $table->unique(['template_id', 'step_order']);
        });

        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('task_id');
            $table->uuid('template_id')->nullable();
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->string('status', 30)->default('pending');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'task_id'])->references(['institution_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->foreign('template_id')->references('id')->on('approval_templates')->nullOnDelete();
            $table->unique(['institution_id', 'id']);
            $table->index(['task_id', 'status']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('workflow_id');
            $table->unsignedSmallInteger('step_order');
            $table->string('approver_type', 30);
            $table->foreignUuid('approver_id')->nullable()->constrained('users');
            $table->foreignUuid('approver_role_id')->nullable()->constrained('roles');
            $table->string('status', 30)->default('waiting');
            $table->string('decision', 30)->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->text('comments')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'workflow_id'])->references(['institution_id', 'id'])->on('approval_workflows')->cascadeOnDelete();
            $table->unique(['workflow_id', 'step_order']);
            $table->index(['institution_id', 'approver_id', 'status']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('approval_template_id')->nullable()->after('approval_status');
            $table->foreign(['institution_id', 'approval_template_id'])->references(['institution_id', 'id'])->on('approval_templates');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE approval_template_steps ADD CONSTRAINT approval_template_steps_type_check CHECK (approver_type IN ('unit_manager','parent_unit_manager','role','user'))");
            DB::statement("ALTER TABLE approval_steps ADD CONSTRAINT approval_steps_status_check CHECK (status IN ('waiting','pending','approved','rejected','skipped'))");

            // Une décision rendue ne peut plus être modifiée silencieusement.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION approval_steps_lock_decisions() RETURNS trigger AS $$
                BEGIN
                    IF OLD.decided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'approval decision is final';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER approval_steps_final
                    BEFORE UPDATE OR DELETE ON approval_steps
                    FOR EACH ROW EXECUTE FUNCTION approval_steps_lock_decisions();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS approval_steps_final ON approval_steps; DROP FUNCTION IF EXISTS approval_steps_lock_decisions();');
        }
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['institution_id', 'approval_template_id']);
            $table->dropColumn('approval_template_id');
        });
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_workflows');
        Schema::dropIfExists('approval_template_steps');
        Schema::dropIfExists('approval_templates');
    }
};
