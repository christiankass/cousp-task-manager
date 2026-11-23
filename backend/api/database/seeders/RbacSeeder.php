<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Permissions et rôles système (§5 et §29.1). Idempotent : peut être relancé.
 * Les permissions des lots suivants (tâches, réunions…) sont déjà déclarées.
 */
class RbacSeeder extends Seeder
{
    public const PERMISSIONS = [
        'org_units.view' => ['organization', 'Consulter les structures'],
        'org_units.manage' => ['organization', 'Gérer les structures'],
        'users.view' => ['users', 'Consulter les utilisateurs'],
        'users.manage' => ['users', 'Gérer les utilisateurs'],
        'roles.assign' => ['users', 'Attribuer des rôles'],
        'settings.manage' => ['administration', 'Gérer les paramètres institutionnels'],
        'audit.view' => ['audit', 'Consulter le journal d\'audit'],
        'tasks.view_own' => ['tasks', 'Consulter ses tâches'],
        'tasks.view_unit' => ['tasks', 'Consulter les tâches de son unité'],
        'tasks.view_all' => ['tasks', 'Consulter toutes les tâches de l\'institution'],
        'tasks.create' => ['tasks', 'Créer une tâche'],
        'tasks.assign' => ['tasks', 'Attribuer une tâche'],
        'tasks.execute' => ['tasks', 'Exécuter ses tâches'],
        'tasks.validate' => ['workflow', 'Valider ou rejeter un livrable'],
        'tasks.delete' => ['tasks', 'Archiver une tâche'],
        'workflow.manage' => ['workflow', 'Configurer les circuits de validation'],
        'meetings.manage' => ['meetings', 'Gérer les réunions et recommandations'],
        'health_events.manage' => ['health_events', 'Gérer les événements sanitaires et missions'],
        'reports.view' => ['reporting', 'Consulter les tableaux de bord et rapports'],
        'reports.export' => ['reporting', 'Exporter les rapports'],
    ];

    public const ROLES = [
        'institution_admin' => ['Administrateur institutionnel', [
            'org_units.view', 'org_units.manage', 'users.view', 'users.manage', 'roles.assign',
            'settings.manage', 'audit.view', 'tasks.view_own', 'tasks.view_all', 'tasks.create',
            'tasks.assign', 'tasks.execute', 'workflow.manage', 'reports.view', 'reports.export',
        ]],
        'general_coordinator' => ['Coordonnateur général', [
            'org_units.view', 'users.view', 'audit.view', 'tasks.view_own', 'tasks.view_all',
            'tasks.create', 'tasks.assign', 'tasks.execute', 'tasks.validate', 'tasks.delete',
            'meetings.manage', 'health_events.manage', 'reports.view', 'reports.export',
        ]],
        'director' => ['Responsable de direction', [
            'org_units.view', 'users.view', 'tasks.view_own', 'tasks.view_unit', 'tasks.create',
            'tasks.assign', 'tasks.execute', 'tasks.validate', 'tasks.delete', 'meetings.manage',
            'reports.view', 'reports.export',
        ]],
        'unit_head' => ['Chef de service', [
            'org_units.view', 'users.view', 'tasks.view_own', 'tasks.view_unit', 'tasks.create',
            'tasks.assign', 'tasks.execute', 'tasks.validate', 'reports.view',
        ]],
        'agent' => ['Agent / Exécutant', ['org_units.view', 'tasks.view_own', 'tasks.execute']],
        'validator' => ['Contrôleur / Validateur', ['org_units.view', 'tasks.view_own', 'tasks.view_unit', 'tasks.validate']],
        'observer' => ['Observateur', ['org_units.view', 'reports.view']],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => [$module, $description]) {
            Permission::query()->updateOrCreate(['code' => $code], ['module' => $module, 'description' => $description]);
        }

        $permissionIds = Permission::query()->pluck('id', 'code');

        foreach (self::ROLES as $code => [$name, $permissions]) {
            $role = Role::query()->updateOrCreate(['institution_id' => null, 'code' => $code], ['name' => $name]);
            $role->permissions()->sync(collect($permissions)->mapWithKeys(fn ($p) => [
                $permissionIds[$p] => ['id' => (string) Str::uuid()],
            ])->all());
        }
    }
}
