<?php

namespace Tests;

use App\Models\Institution;
use App\Models\InstitutionUserMembership;
use App\Models\OrganizationUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserUnitMembership;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public const PASSWORD = 'Secret@Pass123';

    protected function setUp(): void
    {
        parent::setUp();

        // La préparation des données se fait hors cloisonnement ; chaque requête API
        // repart ensuite cloisonnée (middleware IsolateTenant).
        $this->tenancy()->setBypass(true);
        $this->seed(RbacSeeder::class);
    }

    /** Les gardes d'authentification sont réinitialisées pour simuler des requêtes indépendantes. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function institution(string $code): Institution
    {
        return Institution::query()->create(['name' => 'Institution '.$code, 'code' => $code]);
    }

    protected function unit(Institution $institution, string $code, ?OrganizationUnit $parent = null, string $type = 'service'): OrganizationUnit
    {
        return OrganizationUnit::query()->create([
            'institution_id' => $institution->id,
            'parent_id' => $parent?->id,
            'name' => 'Unité '.$code,
            'code' => $code,
            'unit_type' => $type,
        ]);
    }

    protected function member(Institution $institution, string $roleCode, ?OrganizationUnit $unit = null, bool $scoped = false, array $attributes = []): User
    {
        $user = User::query()->create($attributes + [
            'name' => 'User '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => self::PASSWORD,
        ]);

        $this->addToInstitution($user, $institution, $roleCode, $unit, $scoped);

        return $user;
    }

    protected function addToInstitution(User $user, Institution $institution, string $roleCode, ?OrganizationUnit $unit = null, bool $scoped = false): void
    {
        InstitutionUserMembership::query()->create(['institution_id' => $institution->id, 'user_id' => $user->id]);

        if ($unit) {
            UserUnitMembership::query()->create([
                'institution_id' => $institution->id,
                'user_id' => $user->id,
                'organization_unit_id' => $unit->id,
                'is_primary' => true,
            ]);
        }

        UserRole::query()->create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution->id))->value('id'),
            'organization_unit_id' => $scoped ? $unit?->id : null,
        ]);
    }

    /** Se connecte via l'API et renvoie les en-têtes d'authentification. */
    protected function loginAs(User $user, Institution $institution): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'institution_code' => $institution->code,
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$response->json('data.access_token')];
    }
}
