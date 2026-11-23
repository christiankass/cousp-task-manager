<?php

namespace Tests\Feature\Tenancy;

use App\Models\OrganizationUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test prioritaire n°1 du dossier de conception (§33.1) : aucune fuite de données
 * entre institutions, ni par l'API, ni directement en base.
 */
class InstitutionIsolationTest extends TestCase
{
    public function test_an_institution_cannot_see_or_use_another_institution_data(): void
    {
        $cousp = $this->institution('COUSP');
        $insp = $this->institution('INSP');
        $cousp_unit = $this->unit($cousp, 'SURV');
        $insp_unit = $this->unit($insp, 'DG');
        $cousp_admin = $this->member($cousp, 'institution_admin', $cousp_unit);
        $insp_agent = $this->member($insp, 'agent', $insp_unit);

        $headers = $this->loginAs($cousp_admin, $cousp);

        // Listes : uniquement les données de l'institution.
        $this->getJson('/api/v1/organization-units', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SURV');
        $this->getJson('/api/v1/users', $headers)
            ->assertOk()
            ->assertJsonMissing(['email' => $insp_agent->email]);

        // Accès direct par identifiant : introuvable.
        $this->getJson("/api/v1/organization-units/{$insp_unit->id}", $headers)->assertNotFound();
        $this->putJson("/api/v1/organization-units/{$insp_unit->id}", ['name' => 'Piraté'], $headers)->assertNotFound();
        $this->getJson("/api/v1/users/{$insp_agent->id}", $headers)->assertNotFound();
        $this->patchJson("/api/v1/users/{$insp_agent->id}/status", ['status' => 'suspended'], $headers)->assertNotFound();

        // Références croisées : refusées.
        $this->postJson('/api/v1/organization-units', [
            'name' => 'Sous-unité', 'code' => 'X1', 'unit_type' => 'team', 'parent_id' => $insp_unit->id,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson("/api/v1/organization-units/{$cousp_unit->id}/members", ['user_id' => $insp_agent->id], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertSame('Unité DG', OrganizationUnit::query()->find($insp_unit->id)->name);
    }

    public function test_row_level_security_filters_raw_queries(): void
    {
        $cousp = $this->institution('COUSP');
        $insp = $this->institution('INSP');
        $this->unit($cousp, 'A');
        $this->unit($insp, 'B');

        $this->tenancy()->setBypass(false);

        // Sans institution active, la base ne renvoie rien, même en SQL brut.
        $this->assertSame(0, DB::table('organization_units')->count());

        $codes = $this->tenancy()->runAs($cousp, fn () => DB::table('organization_units')->pluck('code')->all());
        $this->assertSame(['A'], $codes);

        // Écrire pour une autre institution est bloqué par la base elle-même.
        $this->expectException(QueryException::class);
        $this->tenancy()->runAs($cousp, fn () => DB::table('organization_units')->insert([
            'id' => (string) Str::uuid(),
            'institution_id' => $insp->id,
            'name' => 'Intrus', 'code' => 'Z', 'unit_type' => 'team', 'is_active' => true,
        ]));
    }

    public function test_application_scope_hides_rows_without_an_active_institution(): void
    {
        $cousp = $this->institution('COUSP');
        $this->unit($cousp, 'A');

        $this->tenancy()->setBypass(false);
        $this->tenancy()->clear();

        $this->assertSame(0, OrganizationUnit::query()->count());
    }
}
