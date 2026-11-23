<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Étend Row Level Security aux tables du lot 1 (voir la migration du lot 0). */
return new class extends Migration
{
    public const TABLES = [
        'task_reference_counters', 'tasks', 'task_assignments', 'task_status_history', 'task_comments',
        'task_attachments', 'approval_templates', 'approval_workflows', 'approval_steps', 'notifications',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                USING (
                    current_setting('app.bypass_rls', true) = 'on'
                    OR institution_id::text = current_setting('app.institution_id', true)
                )
                WITH CHECK (
                    current_setting('app.bypass_rls', true) = 'on'
                    OR institution_id::text = current_setting('app.institution_id', true)
                )
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
