<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskStatusHistory;

class TaskLifecycleTest extends TaskTestCase
{
    public function test_scenario_1_create_assign_execute_submit_and_validate(): void
    {
        $id = $this->createTask(['expected_result' => 'Rapport validé']);

        $task = Task::query()->find($id);
        $this->assertMatchesRegularExpression('/^COUSP-\d{4}-000001$/', $task->reference);
        $this->assertContains('task_assigned', $this->notificationsOf($this->agent));

        $agent = $this->loginAs($this->agent, $this->cousp);

        $this->getJson('/api/v1/tasks?assignee=me', $agent)->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.status', 'todo')
            ->assertJsonPath('data.0.can.submit', true);

        $this->patchJson("/api/v1/tasks/{$id}/status", ['status' => 'in_progress'], $agent)->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->patchJson("/api/v1/tasks/{$id}/progress", ['progress' => 60], $agent)->assertOk();
        $this->postJson("/api/v1/tasks/{$id}/comments", ['content' => 'Données reçues de 3 provinces sur 5.'], $agent)->assertCreated();
        $this->assertContains('task_commented', $this->notificationsOf($this->head));

        $this->postJson("/api/v1/tasks/{$id}/submit", ['comment' => 'Rapport joint'], $agent)
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.approval_status', 'pending');
        $this->assertContains('task_submitted', $this->notificationsOf($this->head));

        // Sans circuit configuré, le chef du service valide.
        $head = $this->loginAs($this->head, $this->cousp);
        $this->getJson("/api/v1/tasks/{$id}", $head)->assertJsonPath('data.can.decide', true);
        $this->postJson("/api/v1/tasks/{$id}/approve", ['comments' => 'Conforme'], $head)
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.approval_status', 'approved');
        $this->assertContains('task_validated', $this->notificationsOf($this->agent));

        $events = TaskStatusHistory::query()->where('task_id', $id)->orderBy('changed_at')->pluck('event')->all();
        $this->assertSame(['created', 'status_changed', 'progress_updated', 'submitted', 'validated'], $events);

        // Une tâche validée est figée (RM09).
        $this->putJson("/api/v1/tasks/{$id}", ['title' => 'Modifié'], $head)->assertStatus(409);
        $this->deleteJson("/api/v1/tasks/{$id}", [], $this->loginAs($this->director, $this->cousp))->assertStatus(409);
    }

    public function test_status_rules(): void
    {
        $id = $this->createTask();
        $agent = $this->loginAs($this->agent, $this->cousp);

        $this->patchJson("/api/v1/tasks/{$id}/status", ['status' => 'validated'], $agent)->assertStatus(409);
        $this->patchJson("/api/v1/tasks/{$id}/status", ['status' => 'on_hold'], $agent)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/v1/tasks/{$id}/status", ['status' => 'on_hold', 'reason' => 'En attente des données du labo'], $agent)->assertOk();

        // Un non-affecté ne peut pas changer le statut.
        $this->patchJson("/api/v1/tasks/{$id}/status", ['status' => 'in_progress'], $this->loginAs($this->head, $this->cousp))->assertForbidden();
    }

    public function test_creation_rules(): void
    {
        $head = $this->loginAs($this->head, $this->cousp);
        $base = [
            'organization_unit_id' => $this->service->id, 'title' => 'T', 'task_type' => 'operational', 'priority' => 'normal',
        ];

        // RM02 : un seul responsable principal.
        $this->postJson('/api/v1/tasks', $base + ['assignees' => [
            ['user_id' => $this->agent->id, 'role' => 'responsible'], ['user_id' => $this->otherAgent->id, 'role' => 'responsible'],
        ]], $head)->assertUnprocessable()->assertJsonValidationErrors('assignees');

        // RM04 : l'échéance ne précède pas le début.
        $this->postJson('/api/v1/tasks', $base + [
            'start_at' => now()->addDays(2)->toIso8601String(), 'due_at' => now()->toIso8601String(),
            'assignees' => [['user_id' => $this->agent->id, 'role' => 'responsible']],
        ], $head)->assertUnprocessable()->assertJsonValidationErrors('due_at');

        // Un chef de service ne crée pas de tâche dans une autre direction.
        $other = $this->unit($this->cousp, 'AUTRE', type: 'direction');
        $this->postJson('/api/v1/tasks', ['organization_unit_id' => $other->id] + $base + [
            'assignees' => [['user_id' => $this->agent->id, 'role' => 'responsible']],
        ], $head)->assertForbidden();

        // Un agent n'a pas le droit de créer des tâches.
        $this->postJson('/api/v1/tasks', $base + ['assignees' => [['user_id' => $this->agent->id, 'role' => 'responsible']]],
            $this->loginAs($this->agent, $this->cousp))->assertForbidden();
    }

    public function test_changing_the_due_date_requires_a_justification(): void
    {
        $id = $this->createTask();
        $head = $this->loginAs($this->head, $this->cousp);
        $newDue = now()->addDays(10)->toIso8601String();

        $this->putJson("/api/v1/tasks/{$id}", ['due_at' => $newDue], $head)->assertUnprocessable()->assertJsonValidationErrors('due_at_reason');
        $this->putJson("/api/v1/tasks/{$id}", ['due_at' => $newDue, 'due_at_reason' => 'Retard de remontée des données'], $head)->assertOk();

        $this->assertDatabaseHas('task_status_history', ['task_id' => $id, 'event' => 'due_date_changed', 'reason' => 'Retard de remontée des données']);
        $this->assertContains('task_updated', $this->notificationsOf($this->agent));
    }

    public function test_subtasks_delegation_and_collaborators(): void
    {
        $id = $this->createTask();
        $head = $this->loginAs($this->head, $this->cousp);

        $sub = $this->createTask(['parent_task_id' => $id, 'title' => 'Collecter les données']);
        $this->getJson("/api/v1/tasks/{$id}", $head)->assertJsonPath('data.subtasks.0.id', $sub);

        $this->postJson("/api/v1/tasks/{$id}/assignments", ['user_id' => $this->otherAgent->id, 'role' => 'collaborator'], $head)->assertCreated();

        // Le responsable délègue : il devient collaborateur.
        $this->postJson("/api/v1/tasks/{$id}/assignments", ['user_id' => $this->otherAgent->id, 'role' => 'responsible'], $this->loginAs($this->agent, $this->cousp))
            ->assertCreated();
        $task = Task::query()->find($id);
        $this->assertSame($this->otherAgent->id, $task->responsibleId());
        $this->assertDatabaseHas('task_assignments', ['task_id' => $id, 'user_id' => $this->agent->id, 'assignment_role' => 'collaborator']);
    }

    public function test_archiving_is_logical_and_requires_permission(): void
    {
        $id = $this->createTask();

        $this->deleteJson("/api/v1/tasks/{$id}", [], $this->loginAs($this->head, $this->cousp))->assertForbidden();
        $this->deleteJson("/api/v1/tasks/{$id}?reason=Doublon", [], $this->loginAs($this->director, $this->cousp))->assertNoContent();

        $this->assertSoftDeleted('tasks', ['id' => $id]);
        $this->getJson("/api/v1/tasks/{$id}", $this->loginAs($this->director, $this->cousp))->assertNotFound();
    }
}
