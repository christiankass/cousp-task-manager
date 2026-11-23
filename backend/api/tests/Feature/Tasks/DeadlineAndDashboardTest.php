<?php

namespace Tests\Feature\Tasks;

use App\Models\AppNotification;
use App\Models\Task;
use App\Notifications\TaskEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class DeadlineAndDashboardTest extends TaskTestCase
{
    /** Test prioritaire 4 du §33.1. */
    public function test_overdue_tasks_are_detected_and_people_are_notified_once(): void
    {
        $id = $this->createTask([
            'start_at' => now()->subDays(5)->toIso8601String(),
            'due_at' => now()->subDay()->toIso8601String(),
        ]);

        $this->artisan('tasks:detect-deadlines')->assertSuccessful();
        $this->artisan('tasks:detect-deadlines')->assertSuccessful();

        $this->assertSame(1, AppNotification::query()->where('user_id', $this->agent->id)->where('notification_type', 'task_overdue')->count());
        $this->assertContains('task_overdue', $this->notificationsOf($this->head));
        // Priorité élevée : le niveau hiérarchique supérieur est aussi alerté.
        $this->assertContains('task_overdue', $this->notificationsOf($this->director));
        $this->assertDatabaseHas('task_status_history', ['task_id' => $id, 'event' => 'overdue_detected']);

        $head = $this->loginAs($this->head, $this->cousp);
        $this->getJson('/api/v1/dashboard/overdue', $head)->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_overdue', true);
        // Le statut et le retard sont distincts : la tâche est « à faire » ET en retard.
        $this->getJson("/api/v1/tasks/{$id}", $head)->assertJsonPath('data.status', 'todo')->assertJsonPath('data.is_overdue', true);

        Notification::assertSentTo($this->agent, TaskEmail::class);
    }

    public function test_due_soon_reminder(): void
    {
        $this->createTask(['due_at' => now()->addHours(5)->toIso8601String()]);
        $this->artisan('tasks:detect-deadlines')->assertSuccessful();

        $this->assertContains('task_due_soon', $this->notificationsOf($this->agent));
    }

    public function test_dashboard_indicators(): void
    {
        $done = $this->createTask(['start_at' => now()->subDays(4)->toIso8601String(), 'due_at' => now()->subDay()->toIso8601String()]);
        $this->createTask(['start_at' => now()->subDays(4)->toIso8601String(), 'due_at' => now()->subDay()->toIso8601String(), 'priority' => 'critical']);
        $this->createTask(['due_at' => now()->addDays(5)->toIso8601String()]);

        $this->postJson("/api/v1/tasks/{$done}/submit", [], $this->loginAs($this->agent, $this->cousp));
        $this->postJson("/api/v1/tasks/{$done}/approve", [], $this->loginAs($this->head, $this->cousp))->assertOk();

        $this->getJson('/api/v1/dashboard/overview', $this->loginAs($this->head, $this->cousp))
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.by_status.validated', 1)
            ->assertJsonPath('data.by_status.todo', 2)
            ->assertJsonPath('data.overdue', 1)
            ->assertJsonPath('data.critical_open', 1)
            // 1 tâche validée sur 2 arrivées à échéance ; la tâche non échue ne pénalise pas.
            ->assertJsonPath('data.execution_rate', 50)
            ->assertJsonPath('data.validation_rate', 100)
            ->assertJsonPath('data.by_unit.0.name', 'Unité SERV');

        // L'autre agent n'a aucune tâche : son tableau de bord individuel est vide.
        $this->getJson('/api/v1/dashboard/overview', $this->loginAs($this->otherAgent, $this->cousp))->assertJsonPath('data.total', 0);
    }

    public function test_notification_center(): void
    {
        $this->createTask();
        $agent = $this->loginAs($this->agent, $this->cousp);

        $this->getJson('/api/v1/notifications/unread-count', $agent)->assertJsonPath('data.count', 1);
        $notification = $this->getJson('/api/v1/notifications', $agent)->assertJsonPath('data.0.type', 'task_assigned')->json('data.0');
        $this->assertStringContainsString('Préparer le rapport', $notification['message']);

        $this->patchJson("/api/v1/notifications/{$notification['id']}/read", [], $agent)->assertOk();
        $this->getJson('/api/v1/notifications/unread-count', $agent)->assertJsonPath('data.count', 0);

        // Personne ne peut lire les notifications d'un autre.
        $this->patchJson("/api/v1/notifications/{$notification['id']}/read", [], $this->loginAs($this->head, $this->cousp))->assertNotFound();
    }

    public function test_notifications_use_the_recipient_language(): void
    {
        $this->agent->update(['preferred_language' => 'en']);
        $this->createTask();

        $this->assertStringStartsWith('New task:', AppNotification::query()->where('user_id', $this->agent->id)->value('title'));
    }

    public function test_attachments_are_private_and_checked(): void
    {
        Storage::fake('local');
        $id = $this->createTask();
        $agent = $this->loginAs($this->agent, $this->cousp);

        $this->post("/api/v1/tasks/{$id}/attachments", ['file' => UploadedFile::fake()->create('script.php', 10, 'text/x-php')], $agent + ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $attachment = $this->post("/api/v1/tasks/{$id}/attachments", ['file' => UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf')], $agent + ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.file_name', 'rapport.pdf')
            ->json('data');

        $this->get("/api/v1/tasks/{$id}/attachments/{$attachment['id']}", $agent)->assertOk()->assertDownload('rapport.pdf');
        $this->get("/api/v1/tasks/{$id}/attachments/{$attachment['id']}", $this->loginAs($this->otherAgent, $this->cousp) + ['Accept' => 'application/json'])->assertNotFound();

        $this->assertNotNull(Task::query()->find($id)->attachments()->first()->checksum);
    }
}
