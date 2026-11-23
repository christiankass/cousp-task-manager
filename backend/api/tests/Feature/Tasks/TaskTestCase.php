<?php

namespace Tests\Feature\Tasks;

use App\Models\AppNotification;
use App\Models\Institution;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Organisation type : une direction (directeur) contenant un service (chef de service)
 * avec deux agents.
 */
abstract class TaskTestCase extends TestCase
{
    protected Institution $cousp;

    protected OrganizationUnit $direction;

    protected OrganizationUnit $service;

    protected User $director;

    protected User $head;

    protected User $agent;

    protected User $otherAgent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->cousp = $this->institution('COUSP');
        $this->direction = $this->unit($this->cousp, 'DIR', type: 'direction');
        $this->service = $this->unit($this->cousp, 'SERV', $this->direction);

        $this->director = $this->member($this->cousp, 'director', $this->direction, scoped: true, attributes: ['name' => 'Directeur', 'email' => 'directeur@test.local']);
        $this->head = $this->member($this->cousp, 'unit_head', $this->service, scoped: true, attributes: ['name' => 'Chef', 'email' => 'chef@test.local']);
        $this->agent = $this->member($this->cousp, 'agent', $this->service, attributes: ['name' => 'Agent', 'email' => 'agent@test.local']);
        $this->otherAgent = $this->member($this->cousp, 'agent', $this->service, attributes: ['name' => 'Autre agent', 'email' => 'autre@test.local']);

        $this->direction->update(['manager_id' => $this->director->id]);
        $this->service->update(['manager_id' => $this->head->id]);
    }

    /** Le chef de service crée une tâche confiée à l'agent ; renvoie son identifiant. */
    protected function createTask(array $overrides = [], ?User $as = null): string
    {
        return $this->postJson('/api/v1/tasks', $overrides + [
            'organization_unit_id' => $this->service->id,
            'title' => 'Préparer le rapport hebdomadaire de surveillance',
            'task_type' => 'administrative',
            'priority' => 'high',
            'start_at' => now()->toIso8601String(),
            'due_at' => now()->addDays(3)->toIso8601String(),
            'assignees' => [['user_id' => $this->agent->id, 'role' => 'responsible']],
        ], $this->loginAs($as ?? $this->head, $this->cousp))->assertCreated()->json('data.id');
    }

    protected function notificationsOf(User $user): array
    {
        return AppNotification::query()->where('user_id', $user->id)->pluck('notification_type')->all();
    }
}
