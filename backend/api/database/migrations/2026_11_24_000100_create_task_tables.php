<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine C : tâches, affectations, historique, commentaires et pièces jointes.
 *
 * Trois informations distinctes (§28.2) : status (opérationnel), approval_status
 * (validation) et le retard, calculé à partir de due_at et validated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reference_counters', function (Blueprint $table) {
            $table->uuid('institution_id');
            $table->smallInteger('year');
            $table->unsignedInteger('last_value')->default(0);
            $table->primary(['institution_id', 'year']);
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('organization_unit_id');
            $table->uuid('parent_task_id')->nullable();
            $table->string('reference', 40);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('expected_result')->nullable();
            $table->string('task_type', 30);
            $table->string('category', 50)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('todo');
            $table->string('approval_status', 30)->default('none');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->unsignedSmallInteger('progress')->default(0);
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'organization_unit_id'])->references(['institution_id', 'id'])->on('organization_units');
            $table->unique(['institution_id', 'reference']);
            $table->unique(['institution_id', 'id']);
            $table->index(['institution_id', 'status']);
            $table->index(['institution_id', 'due_at']);
            $table->index(['institution_id', 'organization_unit_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign(['institution_id', 'parent_task_id'])->references(['institution_id', 'id'])->on('tasks');
        });

        Schema::create('task_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('task_id');
            $table->foreignUuid('user_id')->constrained();
            $table->string('assignment_role', 30);
            $table->foreignUuid('assigned_by')->nullable()->constrained('users');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'task_id'])->references(['institution_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->unique(['task_id', 'user_id']);
            $table->index(['institution_id', 'user_id']);
        });

        Schema::create('task_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('task_id');
            $table->string('event', 40);
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'task_id'])->references(['institution_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->index(['task_id', 'changed_at']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('task_id');
            $table->foreignUuid('user_id')->constrained();
            $table->text('content');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'task_id'])->references(['institution_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->index(['task_id', 'created_at']);
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('task_id');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->string('file_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->string('storage_path', 500);
            $table->char('checksum', 64);
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign(['institution_id', 'task_id'])->references(['institution_id', 'id'])->on('tasks')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_task_type_check CHECK (task_type IN ('administrative','operational'))");
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('low','normal','high','critical'))");
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('todo','in_progress','on_hold','submitted','validated','rejected'))");
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_approval_status_check CHECK (approval_status IN ('none','pending','approved','rejected'))");
            DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_dates_check CHECK (due_at IS NULL OR start_at IS NULL OR due_at >= start_at)');
            DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_progress_check CHECK (progress BETWEEN 0 AND 100)');
            DB::statement("ALTER TABLE task_assignments ADD CONSTRAINT task_assignments_role_check CHECK (assignment_role IN ('responsible','collaborator'))");
            // RM02 : au plus un responsable principal par tâche.
            DB::statement("CREATE UNIQUE INDEX task_one_responsible ON task_assignments (task_id) WHERE assignment_role = 'responsible'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_status_history');
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_reference_counters');
    }
};
