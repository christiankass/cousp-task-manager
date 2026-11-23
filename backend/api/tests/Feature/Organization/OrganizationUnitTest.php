<?php

namespace Tests\Feature\Organization;

use App\Models\AuditLog;
use Tests\TestCase;

class OrganizationUnitTest extends TestCase
{
    public function test_admin_builds_the_organisation_tree(): void
    {
        $cousp = $this->institution('COUSP');
        $admin = $this->member($cousp, 'institution_admin');
        $headers = $this->loginAs($admin, $cousp);

        $direction = $this->postJson('/api/v1/organization-units', [
            'name' => 'Surveillance épidémiologique', 'code' => 'surv', 'unit_type' => 'direction',
        ], $headers)->assertCreated()->assertJsonPath('data.code', 'SURV')->json('data.id');

        $this->postJson('/api/v1/organization-units', [
            'name' => 'Analyse des données', 'code' => 'SURV-DATA', 'unit_type' => 'service', 'parent_id' => $direction,
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/organization-units', [
            'name' => 'Doublon', 'code' => 'SURV', 'unit_type' => 'service',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->getJson('/api/v1/organization-units?tree=1', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.code', 'SURV')
            ->assertJsonPath('data.0.children.0.code', 'SURV-DATA');

        $this->assertTrue(AuditLog::query()->where('action', 'organization_unit.created')->exists());
    }

    public function test_a_unit_cannot_become_its_own_descendant(): void
    {
        $cousp = $this->institution('COUSP');
        $parent = $this->unit($cousp, 'P', type: 'direction');
        $child = $this->unit($cousp, 'C', $parent);
        $admin = $this->member($cousp, 'institution_admin');
        $headers = $this->loginAs($admin, $cousp);

        $this->putJson("/api/v1/organization-units/{$parent->id}", ['parent_id' => $child->id], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_deleting_a_unit_only_deactivates_it(): void
    {
        $cousp = $this->institution('COUSP');
        $unit = $this->unit($cousp, 'A');
        $admin = $this->member($cousp, 'institution_admin');
        $headers = $this->loginAs($admin, $cousp);

        $this->deleteJson("/api/v1/organization-units/{$unit->id}", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('organization_units', ['id' => $unit->id, 'is_active' => false]);
    }
}
