<?php

namespace Tests\Feature\Tasks;

use App\Models\ApprovalStep;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Tests prioritaires 2 et 3 du §33.1. */
class ApprovalWorkflowTest extends TaskTestCase
{
    private function twoLevelCircuit(): void
    {
        $admin = $this->member($this->cousp, 'institution_admin');
        $this->postJson('/api/v1/approval-templates', [
            'name' => 'Chef de service puis directeur',
            'is_default' => true,
            'steps' => [['approver_type' => 'unit_manager'], ['approver_type' => 'parent_unit_manager']],
        ], $this->loginAs($admin, $this->cousp))->assertCreated();
    }

    private function submit(string $id): void
    {
        $this->postJson("/api/v1/tasks/{$id}/submit", [], $this->loginAs($this->agent, $this->cousp))->assertOk();
    }

    public function test_task_is_closed_only_after_the_last_required_approval(): void
    {
        $this->twoLevelCircuit();
        $id = $this->createTask();
        $this->submit($id);

        // Le directeur ne peut pas sauter l'étape du chef de service.
        $director = $this->loginAs($this->director, $this->cousp);
        $this->postJson("/api/v1/tasks/{$id}/approve", [], $director)->assertForbidden();

        $this->postJson("/api/v1/tasks/{$id}/approve", ['comments' => 'OK niveau service'], $this->loginAs($this->head, $this->cousp))
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->assertContains('task_submitted', $this->notificationsOf($this->director));

        $this->postJson("/api/v1/tasks/{$id}/approve", ['comments' => 'OK direction'], $this->loginAs($this->director, $this->cousp))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated');

        $this->getJson("/api/v1/tasks/{$id}/approvals", $director)
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.steps.0.decided_by.name', 'Chef')
            ->assertJsonPath('data.0.steps.1.decided_by.name', 'Directeur');
    }

    public function test_rejection_returns_the_task_for_correction_and_keeps_history(): void
    {
        $id = $this->createTask();
        $this->submit($id);
        $head = $this->loginAs($this->head, $this->cousp);

        $this->postJson("/api/v1/tasks/{$id}/reject", [], $head)->assertUnprocessable()->assertJsonValidationErrors('comments');
        $this->postJson("/api/v1/tasks/{$id}/request-correction", ['comments' => 'Compléter la partie terrain'], $head)
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertContains('task_rejected', $this->notificationsOf($this->agent));
        $this->assertDatabaseHas('task_status_history', ['task_id' => $id, 'event' => 'rejected', 'reason' => 'Compléter la partie terrain']);

        // L'agent corrige et soumet de nouveau : un nouveau circuit démarre, l'ancien est conservé.
        $agent = $this->loginAs($this->agent, $this->cousp);
        $this->patchJson("/api/v1/tasks/{$id}/status", ['status' => 'in_progress'], $agent)->assertOk();
        $this->submit($id);
        $this->postJson("/api/v1/tasks/{$id}/approve", [], $this->loginAs($this->head, $this->cousp))->assertJsonPath('data.status', 'validated');

        $this->getJson("/api/v1/tasks/{$id}/approvals", $agent)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.steps.0.comments', 'Compléter la partie terrain')
            ->assertJsonPath('data.1.status', 'approved');
    }

    public function test_nobody_validates_their_own_work(): void
    {
        // Le chef de service se confie une tâche : c'est le directeur qui valide.
        $id = $this->postJson('/api/v1/tasks', [
            'organization_unit_id' => $this->service->id, 'title' => 'Tâche du chef', 'task_type' => 'administrative',
            'priority' => 'normal', 'assignees' => [['user_id' => $this->head->id, 'role' => 'responsible']],
        ], $this->loginAs($this->head, $this->cousp))->assertCreated()->json('data.id');

        $head = $this->loginAs($this->head, $this->cousp);
        $this->postJson("/api/v1/tasks/{$id}/submit", [], $head)->assertOk();
        $this->postJson("/api/v1/tasks/{$id}/approve", [], $head)->assertForbidden();
        $this->postJson("/api/v1/tasks/{$id}/approve", [], $this->loginAs($this->director, $this->cousp))->assertJsonPath('data.status', 'validated');
    }

    public function test_only_the_responsible_submits_and_the_server_picks_the_approver(): void
    {
        $id = $this->createTask();
        $this->postJson("/api/v1/tasks/{$id}/submit", [], $this->loginAs($this->head, $this->cousp))->assertStatus(409);
        $this->submit($id);

        // Un identifiant de valideur envoyé par le client est ignoré : le directeur voit la
        // tâche mais n'est pas le valideur de l'étape en cours.
        $this->postJson("/api/v1/tasks/{$id}/approve", ['approver_id' => $this->director->id], $this->loginAs($this->director, $this->cousp))
            ->assertForbidden();
        // Un agent qui ne voit pas la tâche ne la trouve même pas.
        $this->postJson("/api/v1/tasks/{$id}/approve", [], $this->loginAs($this->otherAgent, $this->cousp))->assertNotFound();
        $this->assertSame('submitted', Task::query()->find($id)->status->value);
    }

    public function test_role_based_step(): void
    {
        $validator = $this->member($this->cousp, 'validator', $this->direction, scoped: true);
        $admin = $this->member($this->cousp, 'institution_admin');
        $this->postJson('/api/v1/approval-templates', [
            'name' => 'Contrôle qualité', 'task_type' => 'administrative',
            'steps' => [['approver_type' => 'role', 'role_code' => 'validator']],
        ], $this->loginAs($admin, $this->cousp))->assertCreated();

        $id = $this->createTask();
        $this->submit($id);
        $this->assertContains('task_submitted', $this->notificationsOf($validator));

        $this->postJson("/api/v1/tasks/{$id}/approve", [], $this->loginAs($this->head, $this->cousp))->assertForbidden();
        $this->postJson("/api/v1/tasks/{$id}/approve", [], $this->loginAs($validator, $this->cousp))->assertJsonPath('data.status', 'validated');
    }

    public function test_decisions_cannot_be_altered_in_the_database(): void
    {
        $id = $this->createTask();
        $this->submit($id);
        $this->postJson("/api/v1/tasks/{$id}/approve", [], $this->loginAs($this->head, $this->cousp))->assertOk();

        $this->assertSame(1, ApprovalStep::query()->whereNotNull('decided_at')->count());
        $this->expectException(QueryException::class);
        DB::table('approval_steps')->update(['decision' => 'rejected']);
    }

    public function test_no_approver_is_reported_clearly(): void
    {
        $this->service->update(['manager_id' => null]);
        $this->direction->update(['manager_id' => null]);

        // Le créateur est aussi le responsable : personne ne peut valider.
        $id = $this->postJson('/api/v1/tasks', [
            'organization_unit_id' => $this->service->id, 'title' => 'Seul', 'task_type' => 'administrative',
            'priority' => 'normal', 'assignees' => [['user_id' => $this->head->id, 'role' => 'responsible']],
        ], $this->loginAs($this->head, $this->cousp))->json('data.id');

        $this->postJson("/api/v1/tasks/{$id}/submit", [], $this->loginAs($this->head, $this->cousp))
            ->assertStatus(409);
    }
}
