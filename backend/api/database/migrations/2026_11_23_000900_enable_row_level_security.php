<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Défense en profondeur : PostgreSQL Row Level Security sur les tables d'une institution.
 *
 * La politique n'autorise que les lignes de l'institution placée dans le paramètre de
 * session app.institution_id. Les traitements système (migrations, seeders, tâches planifiées,
 * administration de plateforme) posent app.bypass_rls = 'on'.
 *
 * FORCE s'applique aussi au propriétaire des tables ; un super-utilisateur PostgreSQL
 * contourne toujours RLS, l'application doit donc se connecter avec un rôle ordinaire.
 */
return new class extends Migration
{
    /** Tables portant une colonne institution_id obligatoire. */
    public const TABLES = [
        'organization_units',
        'user_unit_memberships',
        'user_roles',
        'institution_user_memberships',
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
