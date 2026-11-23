<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\User;
use Tests\Feature\Tasks\TaskTestCase;

class ReportExportTest extends TaskTestCase
{
    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coordinator = $this->member($this->cousp, 'general_coordinator', attributes: ['name' => 'Coordonnateur', 'email' => 'coord@test.local']);
    }

    public function test_task_report_preview_and_exports(): void
    {
        $this->createTask(['title' => 'Tâche du rapport']);
        $this->createTask(['title' => 'Tâche en retard', 'start_at' => now()->subDays(5)->toIso8601String(), 'due_at' => now()->subDay()->toIso8601String()]);
        $headers = $this->loginAs($this->coordinator, $this->cousp);

        $json = $this->getJson('/api/v1/reports/tasks', $headers)->assertOk()->json('data');
        $this->assertSame(2, $json['indicators']['total']);
        $this->assertSame(1, $json['indicators']['overdue']);
        $this->assertSame($this->service->id, $json['by_unit'][0]['id']);

        $xlsx = $this->get('/api/v1/reports/tasks?format=xlsx', $headers)->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());

        $pdf = $this->get('/api/v1/reports/tasks?format=pdf&from='.now()->subWeek()->toDateString(), $headers)->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->assertSame(2, AuditLog::query()->where('action', 'report.exported')->count());
    }

    public function test_reports_respect_permissions_and_scope(): void
    {
        $this->createTask();
        // L'agent n'a pas accès aux rapports.
        $this->getJson('/api/v1/reports/tasks', $this->loginAs($this->agent, $this->cousp))->assertForbidden();

        // Le chef de service consulte mais ne télécharge pas ; il ne voit que son périmètre.
        $other = $this->unit($this->cousp, 'AUTRE');
        $this->createTask(['organization_unit_id' => $other->id], $this->coordinator);
        $head = $this->loginAs($this->head, $this->cousp);
        $this->assertSame(1, $this->getJson('/api/v1/reports/tasks', $head)->json('data.indicators.total'));
        $this->getJson('/api/v1/reports/tasks?format=xlsx', $head)->assertForbidden();
    }

    public function test_meeting_and_event_reports(): void
    {
        $headers = $this->loginAs($this->coordinator, $this->cousp);
        $event = $this->postJson('/api/v1/health-events', [
            'title' => 'Mpox', 'event_type' => 'mpox', 'severity' => 'high', 'start_at' => now()->toIso8601String(),
        ], $headers)->json('data');
        $meeting = $this->postJson('/api/v1/meetings', [
            'title' => 'Réunion', 'meeting_type' => 'crisis', 'starts_at' => now()->toIso8601String(), 'health_event_id' => $event['id'],
        ], $headers)->json('data');
        $this->postJson("/api/v1/meetings/{$meeting['id']}/recommendations", ['content' => 'Former les relais'], $headers)->assertCreated();

        $this->assertStringStartsWith('%PDF', $this->get("/api/v1/reports/meetings/{$meeting['id']}", $headers)->assertOk()->getContent());
        $this->assertStringStartsWith('PK', $this->get("/api/v1/reports/meetings/{$meeting['id']}?format=xlsx", $headers)->assertOk()->streamedContent());
        $this->assertStringStartsWith('%PDF', $this->get("/api/v1/reports/health-events/{$event['id']}", $headers)->assertOk()->getContent());

        $this->get("/api/v1/reports/health-events/{$event['id']}", $this->loginAs($this->agent, $this->cousp))->assertForbidden();
    }
}
