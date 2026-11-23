<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskVisibilityTest extends TaskTestCase
{
    public function test_each_profile_sees_the_right_tasks(): void
    {
        $id = $this->createTask();
        $otherUnit = $this->unit($this->cousp, 'LOG', type: 'direction');
        $outsiderHead = $this->member($this->cousp, 'unit_head', $otherUnit, scoped: true);
        $coordinator = $this->member($this->cousp, 'general_coordinator');

        $this->getJson("/api/v1/tasks/{$id}", $this->loginAs($this->agent, $this->cousp))->assertOk();
        $this->getJson("/api/v1/tasks/{$id}", $this->loginAs($this->director, $this->cousp))->assertOk();
        $this->getJson("/api/v1/tasks/{$id}", $this->loginAs($coordinator, $this->cousp))->assertOk();

        // Un agent du même service non affecté, ou un chef d'une autre direction : rien.
        $this->getJson("/api/v1/tasks/{$id}", $this->loginAs($this->otherAgent, $this->cousp))->assertNotFound();
        $this->getJson('/api/v1/tasks', $this->loginAs($this->otherAgent, $this->cousp))->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/tasks/{$id}", $this->loginAs($outsiderHead, $this->cousp))->assertNotFound();
    }

    public function test_tasks_never_leak_between_institutions(): void
    {
        $id = $this->createTask();

        $insp = $this->institution('INSP');
        $inspUnit = $this->unit($insp, 'DG', type: 'direction');
        $inspCoordinator = $this->member($insp, 'general_coordinator', $inspUnit);
        $inspHead = $this->member($insp, 'unit_head', $inspUnit, scoped: true);
        $inspAgent = $this->member($insp, 'agent', $inspUnit);
        $inspUnit->update(['manager_id' => $inspHead->id]);

        $headers = $this->loginAs($inspCoordinator, $insp);
        $this->getJson("/api/v1/tasks/{$id}", $headers)->assertNotFound();
        $this->getJson('/api/v1/tasks', $headers)->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/tasks/{$id}/comments", ['content' => 'x'], $headers)->assertNotFound();
        $this->getJson('/api/v1/dashboard/overview', $headers)->assertJsonPath('data.total', 0);

        // Une tâche INSP ne peut pas être affectée à un agent COUSP.
        $this->postJson('/api/v1/tasks', [
            'organization_unit_id' => $inspUnit->id, 'title' => 'x', 'task_type' => 'administrative', 'priority' => 'normal',
            'assignees' => [['user_id' => $this->agent->id, 'role' => 'responsible']],
        ], $this->loginAs($inspHead, $insp))->assertUnprocessable();

        // Les références sont numérotées par institution.
        $inspTask = $this->postJson('/api/v1/tasks', [
            'organization_unit_id' => $inspUnit->id, 'title' => 'x', 'task_type' => 'administrative', 'priority' => 'normal',
            'assignees' => [['user_id' => $inspAgent->id, 'role' => 'responsible']],
        ], $this->loginAs($inspHead, $insp))->assertCreated();
        $this->assertMatchesRegularExpression('/^INSP-\d{4}-000001$/', $inspTask->json('data.reference'));

        // Défense en profondeur : la base elle-même cache les tâches COUSP au contexte INSP.
        $this->tenancy()->setBypass(false);
        $this->assertSame(0, $this->tenancy()->runAs($insp, fn () => DB::table('tasks')->where('id', $id)->count()));
        $this->assertSame(1, $this->tenancy()->runAs($this->cousp, fn () => DB::table('tasks')->where('id', $id)->count()));
        $this->tenancy()->setBypass(true);
        $this->assertSame(2, Task::query()->count());
    }

    public function test_filters_and_sorting(): void
    {
        $this->createTask(['title' => 'Alpha', 'priority' => 'low']);
        $this->createTask(['title' => 'Bravo', 'priority' => 'critical', 'task_type' => 'operational']);
        $head = $this->loginAs($this->head, $this->cousp);

        $this->getJson('/api/v1/tasks?sort=-priority', $head)->assertJsonPath('data.0.title', 'Bravo');
        $this->getJson('/api/v1/tasks?task_type=operational', $head)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tasks?search=alph', $head)->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Alpha');
        $this->getJson('/api/v1/tasks?status=todo,in_progress&per_page=1', $head)->assertJsonPath('meta.pagination.total', 2);
    }
}
