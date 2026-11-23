<?php

namespace Tests\Feature\Coordination;

use App\Models\User;
use Tests\Feature\Tasks\TaskTestCase;

class HealthEventMissionTest extends TaskTestCase
{
    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coordinator = $this->member($this->cousp, 'general_coordinator', attributes: ['name' => 'Coordonnateur', 'email' => 'coord@test.local']);
    }

    private function createEvent(array $overrides = []): array
    {
        return $this->postJson('/api/v1/health-events', $overrides + [
            'title' => 'Épidémie de choléra – Kinshasa',
            'event_type' => 'cholera',
            'severity' => 'high',
            'location' => 'Kinshasa, ZS Limete',
            'start_at' => now()->subDays(2)->toIso8601String(),
            'coordinator_id' => $this->director->id,
        ], $this->loginAs($this->coordinator, $this->cousp))->assertCreated()->json('data');
    }

    public function test_response_actions_are_followed_in_the_event_space(): void
    {
        $this->postJson('/api/v1/health-events', [], $this->loginAs($this->head, $this->cousp))->assertForbidden();

        $event = $this->createEvent();
        $this->assertMatchesRegularExpression('/^COUSP-EVT-\d{4}-001$/', $event['reference']);

        // Le coordonnateur désigné peut créer des actions et des rapports de situation.
        $headers = $this->loginAs($this->director, $this->cousp);
        $task = $this->postJson("/api/v1/health-events/{$event['id']}/tasks", [
            'organization_unit_id' => $this->service->id,
            'title' => 'Prépositionner les kits choléra',
            'due_at' => now()->addDay()->toIso8601String(),
            'assignees' => [['user_id' => $this->agent->id, 'role' => 'responsible']],
        ], $headers)->assertCreated()->json('data');
        $this->assertSame($event['id'], $task['health_event']['id']);
        $this->assertSame('operational', $task['task_type']);

        $this->postJson("/api/v1/health-events/{$event['id']}/situation-reports", [
            'report_date' => now()->toDateString(), 'summary' => '12 nouveaux cas suspects', 'needs' => 'SRO',
        ], $headers)->assertCreated();

        $show = $this->getJson("/api/v1/health-events/{$event['id']}", $this->loginAs($this->agent, $this->cousp))->assertOk()->json('data');
        $this->assertSame(1, $show['stats']['tasks_total']);
        $this->assertSame($this->service->id, $show['teams'][0]['id']);
        $this->assertCount(1, $show['situation_reports']);
        $this->assertFalse($show['can']['manage']);

        // Une tâche ordinaire peut aussi être rattachée à l'événement, et filtrée.
        $this->createTask(['health_event_id' => $event['id']]);
        $this->assertCount(2, $this->getJson("/api/v1/tasks?health_event_id={$event['id']}", $this->loginAs($this->head, $this->cousp))->json('data'));
    }

    public function test_a_closed_event_accepts_no_new_actions(): void
    {
        $event = $this->createEvent();
        $headers = $this->loginAs($this->coordinator, $this->cousp);

        $this->postJson("/api/v1/health-events/{$event['id']}/close", [], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/health-events/{$event['id']}/close", ['closure_note' => 'Fin de l\'épidémie déclarée'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/v1/health-events/{$event['id']}/close", ['closure_note' => 'x'], $headers)->assertStatus(409);

        $this->postJson('/api/v1/tasks', [
            'organization_unit_id' => $this->service->id, 'title' => 'x', 'task_type' => 'operational', 'priority' => 'high',
            'health_event_id' => $event['id'], 'assignees' => [['user_id' => $this->agent->id, 'role' => 'responsible']],
        ], $this->loginAs($this->head, $this->cousp))->assertUnprocessable()->assertJsonValidationErrors(['health_event_id']);
    }

    public function test_a_mission_report_goes_through_validation(): void
    {
        $event = $this->createEvent();
        $headers = $this->loginAs($this->coordinator, $this->cousp);

        $mission = $this->postJson('/api/v1/missions', [
            'title' => 'Investigation des cas suspects',
            'destination' => 'ZS Limete',
            'objectives' => 'Investiguer les cas et prélever des échantillons',
            'departure_at' => now()->addDay()->toIso8601String(),
            'return_at' => now()->addDays(4)->toIso8601String(),
            'organization_unit_id' => $this->service->id,
            'health_event_id' => $event['id'],
            'mission_leader_id' => $this->agent->id,
            'members' => [['user_id' => $this->otherAgent->id, 'assignment' => 'Prélèvements']],
        ], $headers)->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^COUSP-MIS-\d{4}-001$/', $mission['reference']);
        $this->assertSame('planned', $mission['status']);
        $this->assertCount(1, $mission['members']);
        $this->assertContains('task_assigned', $this->notificationsOf($this->agent));
        $this->assertContains('task_assigned', $this->notificationsOf($this->otherAgent));

        // L'équipe la voit dans « mes missions ».
        $this->assertCount(1, $this->getJson('/api/v1/missions?mine=1', $this->loginAs($this->otherAgent, $this->cousp))->json('data'));

        $this->postJson("/api/v1/missions/{$mission['id']}/report", ['report' => 'x'], $this->loginAs($this->otherAgent, $this->cousp))->assertForbidden();

        $agent = $this->loginAs($this->agent, $this->cousp);
        $this->getJson("/api/v1/missions/{$mission['id']}", $agent)->assertJsonPath('data.can.report', true);
        $this->postJson("/api/v1/missions/{$mission['id']}/report", ['report' => '15 cas investigués, 8 prélèvements envoyés à l\'INRB.'], $agent)
            ->assertOk()->assertJsonPath('data.status', 'report_submitted');

        // Le chef de service valide le rapport comme n'importe quel livrable.
        $this->postJson("/api/v1/tasks/{$mission['task']['id']}/approve", [], $this->loginAs($this->head, $this->cousp))->assertOk();
        $this->getJson("/api/v1/missions/{$mission['id']}", $agent)->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, $this->getJson("/api/v1/health-events/{$event['id']}", $headers)->json('data.stats.by_status.validated'));
    }

    public function test_only_event_managers_create_missions(): void
    {
        $this->postJson('/api/v1/missions', [
            'title' => 'x', 'destination' => 'x', 'objectives' => 'x',
            'departure_at' => now()->toIso8601String(), 'return_at' => now()->addDay()->toIso8601String(),
            'organization_unit_id' => $this->service->id, 'mission_leader_id' => $this->agent->id,
        ], $this->loginAs($this->head, $this->cousp))->assertForbidden();
    }
}
