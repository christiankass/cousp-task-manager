<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Matrice d'autorisation (§29.1). */
class PermissionTest extends TestCase
{
    public function test_agent_can_read_the_organisation_but_not_manage_it(): void
    {
        $cousp = $this->institution('COUSP');
        $agent = $this->member($cousp, 'agent');
        $headers = $this->loginAs($agent, $cousp);

        $this->getJson('/api/v1/organization-units', $headers)->assertOk();
        $this->postJson('/api/v1/organization-units', ['name' => 'X', 'code' => 'X', 'unit_type' => 'team'], $headers)
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->getJson('/api/v1/users', $headers)->assertForbidden();
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@test.local'], $headers)->assertForbidden();
    }

    public function test_observer_cannot_list_users(): void
    {
        $cousp = $this->institution('COUSP');
        $observer = $this->member($cousp, 'observer');

        $this->getJson('/api/v1/users', $this->loginAs($observer, $cousp))->assertForbidden();
    }

    public function test_admin_adds_a_user_and_assigns_a_scoped_role(): void
    {
        $cousp = $this->institution('COUSP');
        $service = $this->unit($cousp, 'SERV');
        $admin = $this->member($cousp, 'institution_admin');
        $headers = $this->loginAs($admin, $cousp);

        $userId = $this->postJson('/api/v1/users', [
            'name' => 'Nouvel agent', 'email' => 'Nouvel.Agent@cousp.local',
            'organization_unit_id' => $service->id, 'role_code' => 'agent',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.email', 'nouvel.agent@cousp.local')
            ->assertJsonPath('data.roles.0.code', 'agent')
            ->json('data.id');

        $this->postJson("/api/v1/users/{$userId}/roles", [
            'role_code' => 'unit_head', 'organization_unit_id' => $service->id,
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/users', ['name' => 'Bis', 'email' => 'nouvel.agent@cousp.local'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $user = User::query()->find($userId);
        $this->tenancy()->runAs($cousp, function () use ($user, $service, $cousp) {
            $other = $this->unit($cousp, 'OTHER');
            $this->assertTrue($user->hasPermissionForUnit('tasks.assign', $service));
            $this->assertFalse($user->hasPermissionForUnit('tasks.assign', $other));
        });
    }

    public function test_a_role_on_a_parent_unit_covers_its_sub_units(): void
    {
        $cousp = $this->institution('COUSP');
        $direction = $this->unit($cousp, 'DIR', type: 'direction');
        $service = $this->unit($cousp, 'SERV', $direction);
        $team = $this->unit($cousp, 'TEAM', $service, 'team');
        $director = $this->member($cousp, 'director', $direction, scoped: true);

        $this->tenancy()->runAs($cousp, function () use ($director, $team) {
            $this->assertTrue($director->hasPermissionForUnit('tasks.validate', $team));
        });
    }

    public function test_only_holders_of_settings_permission_can_grant_admin_role(): void
    {
        $cousp = $this->institution('COUSP');
        // Rôle personnalisé disposant seulement de roles.assign.
        $role = Role::query()->create(['institution_id' => $cousp->id, 'name' => 'RH', 'code' => 'hr']);
        $role->permissions()->attach(Permission::query()->where('code', 'roles.assign')->value('id'), ['id' => (string) Str::uuid()]);
        $hr = $this->member($cousp, 'hr');
        $target = $this->member($cousp, 'agent');

        $this->postJson("/api/v1/users/{$target->id}/roles", ['role_code' => 'institution_admin'], $this->loginAs($hr, $cousp))
            ->assertForbidden();
    }

    public function test_platform_routes_are_reserved_to_the_platform_admin(): void
    {
        $cousp = $this->institution('COUSP');
        $admin = $this->member($cousp, 'institution_admin');
        $this->getJson('/api/v1/platform/institutions', $this->loginAs($admin, $cousp))->assertForbidden();

        $super = $this->member($cousp, 'observer');
        $super->forceFill(['is_platform_admin' => true])->save();
        $headers = $this->loginAs($super, $cousp);

        $this->postJson('/api/v1/platform/institutions', [
            'name' => 'Institut National de Santé Publique', 'code' => 'insp', 'admin_user_id' => $admin->id,
        ], $headers)->assertCreated()->assertJsonPath('data.code', 'INSP');

        $this->getJson('/api/v1/platform/institutions', $headers)->assertOk()->assertJsonCount(2, 'data');

        // Le premier administrateur peut désormais se connecter à la nouvelle institution.
        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email, 'password' => self::PASSWORD, 'institution_code' => 'INSP',
        ])->assertOk();
    }
}
