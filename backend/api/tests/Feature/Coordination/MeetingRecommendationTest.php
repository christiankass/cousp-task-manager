<?php

namespace Tests\Feature\Coordination;

use App\Models\Task;
use App\Models\User;
use Tests\Feature\Tasks\TaskTestCase;

class MeetingRecommendationTest extends TaskTestCase
{
    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coordinator = $this->member($this->cousp, 'general_coordinator', attributes: ['name' => 'Coordonnateur', 'email' => 'coord@test.local']);
    }

    private function createMeeting(array $overrides = [], ?User $as = null): array
    {
        return $this->postJson('/api/v1/meetings', $overrides + [
            'title' => 'Réunion de coordination hebdomadaire',
            'meeting_type' => 'coordination',
            'starts_at' => now()->toIso8601String(),
            'organization_unit_id' => $this->direction->id,
            'chair_id' => $this->coordinator->id,
            'agenda' => "1. Situation choléra\n2. Divers",
        ], $this->loginAs($as ?? $this->coordinator, $this->cousp))->assertCreated()->json('data');
    }

    public function test_a_recommendation_becomes_a_task_that_keeps_its_origin(): void
    {
        $meeting = $this->createMeeting();
        $this->assertMatchesRegularExpression('/^COUSP-REU-\d{4}-001$/', $meeting['reference']);

        $headers = $this->loginAs($this->coordinator, $this->cousp);
        $this->postJson("/api/v1/meetings/{$meeting['id']}/participants", ['participants' => [
            ['user_id' => $this->head->id, 'attendance' => 'present'],
            ['external_name' => 'Représentant OMS', 'organization' => 'OMS', 'attendance' => 'present'],
            ['organization' => 'Sans nom'],
        ]], $headers)->assertUnprocessable();

        $this->postJson("/api/v1/meetings/{$meeting['id']}/participants", ['participants' => [
            ['user_id' => $this->head->id, 'attendance' => 'present'],
            ['external_name' => 'Représentant OMS', 'organization' => 'OMS', 'attendance' => 'present'],
        ]], $headers)->assertCreated()->assertJsonCount(2, 'data');

        $rec = $this->postJson("/api/v1/meetings/{$meeting['id']}/recommendations", [
            'content' => 'Renforcer la surveillance épidémiologique dans les zones de Limete et Masina',
            'organization_unit_id' => $this->service->id,
            'responsible_id' => $this->agent->id,
            'due_at' => now()->addDays(7)->toIso8601String(),
            'expected_result' => 'Rapport de surveillance renforcée',
        ], $headers)->assertCreated()->json('data');
        $this->assertSame($meeting['reference'].'-R01', $rec['reference']);
        $this->assertSame('not_converted', $rec['execution_status']);

        $task = $this->postJson("/api/v1/recommendations/{$rec['id']}/convert-to-task", [
            'collaborator_ids' => [$this->otherAgent->id],
        ], $headers)->assertCreated()->json('data');

        $this->assertSame($rec['id'], $task['recommendation']['id']);
        $this->assertSame($this->agent->id, collect($task['assignees'])->firstWhere('role', 'responsible')['user_id']);
        $this->assertStringContainsString($rec['reference'], $task['description']);
        $this->assertContains('task_assigned', $this->notificationsOf($this->agent));

        // La recommandation suit l'exécution de sa tâche.
        $show = $this->getJson("/api/v1/meetings/{$meeting['id']}", $headers)->assertOk()->json('data');
        $this->assertSame('in_progress', $show['recommendations'][0]['execution_status']);
        $this->assertSame(1, $show['follow_up']['in_progress']);

        Task::query()->whereKey($task['id'])->update(['status' => 'validated', 'validated_at' => now()]);
        $show = $this->getJson("/api/v1/meetings/{$meeting['id']}", $headers)->json('data');
        $this->assertSame('done', $show['recommendations'][0]['execution_status']);
        $this->assertEquals(100, $show['follow_up']['execution_rate']);
    }

    public function test_the_next_meeting_reviews_the_previous_recommendations(): void
    {
        $first = $this->createMeeting(['starts_at' => now()->subWeek()->toIso8601String()]);
        $headers = $this->loginAs($this->coordinator, $this->cousp);
        foreach (['Recommandation A', 'Recommandation B'] as $content) {
            $this->postJson("/api/v1/meetings/{$first['id']}/recommendations", ['content' => $content, 'due_at' => now()->subDay()->toIso8601String()], $headers)->assertCreated();
        }

        $second = $this->createMeeting();
        $show = $this->getJson("/api/v1/meetings/{$second['id']}", $headers)->assertOk()->json('data');

        $this->assertSame($first['id'], $show['previous_meeting']['id']);
        $this->assertSame(2, $show['previous_meeting']['follow_up']['total']);
        $this->assertSame(2, $show['previous_meeting']['follow_up']['overdue']);
        $this->assertEquals(0, $show['previous_meeting']['follow_up']['execution_rate']);

        $list = $this->getJson('/api/v1/recommendations?execution_status=overdue', $headers)->assertOk();
        $this->assertCount(2, $list->json('data'));
    }

    public function test_only_meeting_managers_can_change_a_meeting(): void
    {
        $this->postJson('/api/v1/meetings', [
            'title' => 'x', 'meeting_type' => 'coordination', 'starts_at' => now()->toIso8601String(),
        ], $this->loginAs($this->agent, $this->cousp))->assertForbidden();

        $meeting = $this->createMeeting(['secretary_id' => $this->agent->id]);

        // Tout membre consulte ; le secrétaire désigné peut rédiger le compte rendu.
        $this->getJson("/api/v1/meetings/{$meeting['id']}", $this->loginAs($this->otherAgent, $this->cousp))->assertOk()->assertJsonPath('data.can.manage', false);
        $this->putJson("/api/v1/meetings/{$meeting['id']}", ['minutes' => 'x'], $this->loginAs($this->otherAgent, $this->cousp))->assertForbidden();
        $this->putJson("/api/v1/meetings/{$meeting['id']}", ['minutes' => 'Compte rendu', 'status' => 'held'], $this->loginAs($this->agent, $this->cousp))
            ->assertOk()->assertJsonPath('data.minutes', 'Compte rendu');
    }

    public function test_converting_still_requires_rights_on_the_target_unit(): void
    {
        $other = $this->unit($this->cousp, 'AUTRE');
        // Le directeur gère les réunions mais n'a pas de droits sur une structure hors de sa direction.
        $meeting = $this->createMeeting(as: $this->director);
        $headers = $this->loginAs($this->director, $this->cousp);
        $rec = $this->postJson("/api/v1/meetings/{$meeting['id']}/recommendations", ['content' => 'Action'], $headers)->json('data');

        $this->postJson("/api/v1/recommendations/{$rec['id']}/convert-to-task", [], $headers)->assertUnprocessable()->assertJsonValidationErrors(['responsible_id']);
        $this->postJson("/api/v1/recommendations/{$rec['id']}/convert-to-task", [
            'organization_unit_id' => $other->id, 'responsible_id' => $this->agent->id,
        ], $headers)->assertForbidden();
        $this->postJson("/api/v1/recommendations/{$rec['id']}/convert-to-task", [
            'organization_unit_id' => $this->service->id, 'responsible_id' => $this->agent->id,
        ], $headers)->assertCreated();
    }

    public function test_meetings_are_isolated_between_institutions(): void
    {
        $meeting = $this->createMeeting();
        $insp = $this->institution('INSP');
        $inspCoordinator = $this->member($insp, 'general_coordinator');

        $headers = $this->loginAs($inspCoordinator, $insp);
        $this->getJson("/api/v1/meetings/{$meeting['id']}", $headers)->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/meetings', $headers)->json('data'));
    }
}
