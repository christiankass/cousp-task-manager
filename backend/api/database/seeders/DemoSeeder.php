<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\InstitutionUserMembership;
use App\Models\OrganizationUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserUnitMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration (jamais en production) : COUSP et INSP avec une
 * organisation illustrative (§4.1) et un compte par profil.
 * Mot de passe de tous les comptes : Cousp@2026!
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Cousp@2026!';

    public function run(): void
    {
        $context = app(TenantContext::class);

        User::query()->updateOrCreate(['email' => 'superadmin@cousp-task.local'], [
            'name' => 'Super administrateur',
            'password' => self::PASSWORD,
        ])->forceFill(['is_platform_admin' => true])->save();

        $cousp = Institution::query()->updateOrCreate(['code' => 'COUSP'], [
            'name' => 'Centre des Opérations d\'Urgence de Santé Publique',
        ]);
        $insp = Institution::query()->updateOrCreate(['code' => 'INSP'], [
            'name' => 'Institut National de Santé Publique',
        ]);

        $context->runAs($cousp, function () {
            $coord = $this->unit('COORD', 'Coordination COUSP', 'direction');
            $surv = $this->unit('SURV', 'Surveillance épidémiologique', 'direction', $coord);
            $this->unit('URG', 'Gestion des urgences', 'direction', $coord);
            $this->unit('LABO', 'Laboratoire', 'division', $coord);
            $this->unit('COMM', 'Communication des risques', 'division', $coord);
            $this->unit('LOG', 'Logistique', 'division', $coord);
            $this->unit('INFO', 'Gestion de l\'information', 'division', $coord);
            $survData = $this->unit('SURV-DATA', 'Service analyse des données', 'service', $surv);

            $this->person('admin@cousp.local', 'Admin COUSP', 'institution_admin', $coord);
            $this->person('coordonnateur@cousp.local', 'Coordonnateur COUSP', 'general_coordinator', $coord);
            $this->person('directeur.surv@cousp.local', 'Directeur Surveillance', 'director', $surv, scoped: true);
            $this->person('chef.data@cousp.local', 'Chef service données', 'unit_head', $survData, scoped: true);
            $this->person('agent@cousp.local', 'Agent COUSP', 'agent', $survData);
            $this->person('validateur@cousp.local', 'Validateur COUSP', 'validator', $surv, scoped: true);
            $this->person('observateur@cousp.local', 'Observateur COUSP', 'observer', $coord);
        });

        $context->runAs($insp, function () {
            $dg = $this->unit('DG', 'Direction générale INSP', 'direction');
            $this->person('admin@insp.local', 'Admin INSP', 'institution_admin', $dg);
            $this->person('agent@insp.local', 'Agent INSP', 'agent', $dg);
        });
    }

    private function unit(string $code, string $name, string $type, ?OrganizationUnit $parent = null): OrganizationUnit
    {
        return OrganizationUnit::query()->updateOrCreate(['code' => $code], [
            'name' => $name,
            'unit_type' => $type,
            'parent_id' => $parent?->id,
        ]);
    }

    private function person(string $email, string $name, string $roleCode, OrganizationUnit $unit, bool $scoped = false): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD]);

        InstitutionUserMembership::query()->firstOrCreate(['user_id' => $user->id], ['status' => 'active']);
        UserUnitMembership::query()->firstOrCreate(
            ['user_id' => $user->id, 'organization_unit_id' => $unit->id],
            ['membership_type' => $roleCode === 'unit_head' || $roleCode === 'director' ? 'manager' : 'member', 'is_primary' => true]
        );
        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('institution_id')->where('code', $roleCode)->value('id'),
            'organization_unit_id' => $scoped ? $unit->id : null,
        ]);

        if (in_array($roleCode, ['director', 'unit_head'], true)) {
            $unit->update(['manager_id' => $user->id]);
        }

        return $user;
    }
}
