<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskRecurrence;
use Illuminate\Support\Carbon;

class RecurringTaskTest extends TaskTestCase
{
    private function createSeries(array $overrides = []): array
    {
        return $this->postJson('/api/v1/task-recurrences', $overrides + [
            'title' => 'Rapport hebdomadaire de surveillance',
            'organization_unit_id' => $this->service->id,
            'task_type' => 'administrative',
            'priority' => 'high',
            'responsible_id' => $this->agent->id,
            'frequency' => 'weekly',
            'weekdays' => [5],
            'due_time' => '16:00',
            'lead_days' => 2,
            'starts_on' => '2026-11-02',
        ], $this->loginAs($this->head, $this->cousp))->assertCreated()->json('data');
    }

    public function test_weekly_series_creates_one_task_per_occurrence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 08:00', 'Africa/Kinshasa'));
        $series = $this->createSeries();

        // Prochains vendredis.
        $this->assertSame('2026-11-27', $series['next_occurrence_on']);
        $this->assertSame(['2026-11-27', '2026-12-04', '2026-12-11', '2026-12-18', '2027-01-01'], array_slice($series['upcoming'], 0, 4) + [4 => '2027-01-01']);

        // Lundi : trop tôt (création 2 jours avant).
        $this->artisan('tasks:generate-recurring')->assertSuccessful();
        $this->assertSame(0, Task::query()->count());

        // Mercredi : la tâche du vendredi est créée, une seule fois.
        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00', 'Africa/Kinshasa'));
        $this->artisan('tasks:generate-recurring');
        $this->artisan('tasks:generate-recurring');
        $task = Task::query()->sole();
        $this->assertSame('2026-11-27', $task->occurrence_on->toDateString());
        $this->assertSame('2026-11-27 16:00', $task->due_at->timezone('Africa/Kinshasa')->format('Y-m-d H:i'));
        $this->assertSame($this->agent->id, $task->responsibleId());
        $this->assertContains('task_assigned', $this->notificationsOf($this->agent));
        $this->assertSame('2026-12-04', TaskRecurrence::query()->sole()->next_occurrence_on->toDateString());
        Carbon::setTestNow();
    }

    public function test_a_long_outage_only_recreates_the_latest_occurrence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00', 'Africa/Kinshasa'));
        $this->createSeries(['frequency' => 'daily', 'weekdays' => null, 'lead_days' => 0, 'starts_on' => '2026-11-01']);

        Carbon::setTestNow(Carbon::parse('2026-11-10 18:00', 'Africa/Kinshasa'));
        $this->artisan('tasks:generate-recurring');
        $this->assertSame(['2026-11-10'], Task::query()->pluck('occurrence_on')->map->toDateString()->all());
        Carbon::setTestNow();
    }

    public function test_monthly_series_uses_the_last_day_when_the_month_is_short(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 08:00', 'Africa/Kinshasa'));
        $series = $this->createSeries(['frequency' => 'monthly', 'weekdays' => null, 'day_of_month' => 31, 'starts_on' => '2027-01-01']);
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], array_slice($series['upcoming'], 0, 3));
        Carbon::setTestNow();
    }

    public function test_series_follow_task_permissions(): void
    {
        $this->postJson('/api/v1/task-recurrences', [
            'title' => 'x', 'organization_unit_id' => $this->service->id, 'task_type' => 'administrative', 'priority' => 'normal',
            'responsible_id' => $this->otherAgent->id, 'frequency' => 'daily', 'starts_on' => now()->toDateString(),
        ], $this->loginAs($this->agent, $this->cousp))->assertForbidden();

        $series = $this->createSeries();
        $this->deleteJson("/api/v1/task-recurrences/{$series['id']}", [], $this->loginAs($this->head, $this->cousp))->assertOk();
        $this->assertFalse(TaskRecurrence::query()->sole()->is_active);
    }
}
