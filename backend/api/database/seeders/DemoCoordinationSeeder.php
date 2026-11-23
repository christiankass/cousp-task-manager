<?php

namespace Database\Seeders;

use App\Models\HealthEvent;
use App\Models\Institution;
use App\Models\OrganizationUnit;
use App\Models\SituationReport;
use App\Models\User;
use App\Services\Coordination\MeetingService;
use App\Services\Coordination\MissionService;
use App\Services\ReferenceGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Démonstration du lot 3 : une riposte choléra, une réunion avec ses recommandations, une mission. */
class DemoCoordinationSeeder extends Seeder
{
    public function run(TenantContext $context, MeetingService $meetings, MissionService $missions, ReferenceGenerator $references): void
    {
        config(['cousp.mail_notifications' => false, 'cousp.push_notifications' => false]);
        $cousp = Institution::query()->where('code', 'COUSP')->firstOrFail();

        $context->runAs($cousp, function () use ($cousp, $meetings, $missions, $references) {
            if (HealthEvent::query()->exists()) {
                return;
            }

            $u = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
            $unit = fn (string $code) => OrganizationUnit::query()->where('code', $code)->firstOrFail();
            [$coord, $dir, $chef, $agent] = [$u('coordonnateur@cousp.local'), $u('directeur.surv@cousp.local'), $u('chef.data@cousp.local'), $u('agent@cousp.local')];

            $event = HealthEvent::query()->create([
                'reference' => $references->next($cousp, ReferenceGenerator::EVENT),
                'title' => 'Épidémie de choléra – Kinshasa',
                'event_type' => 'cholera',
                'severity' => 'high',
                'status' => 'active',
                'location' => 'Kinshasa : ZS Limete, Masina, Kingabwa',
                'description' => 'Hausse des cas de diarrhée aqueuse aiguë signalée par trois zones de santé.',
                'start_at' => now()->subDays(5),
                'coordinator_id' => $dir->id,
                'created_by' => $coord->id,
            ]);
            SituationReport::query()->create([
                'health_event_id' => $event->id, 'report_date' => now()->subDay()->toDateString(), 'author_id' => $dir->id,
                'summary' => '42 cas suspects cumulés, 3 décès. Deux zones de santé en alerte.',
                'actions' => 'Prépositionnement des kits, sensibilisation communautaire.',
                'needs' => 'SRO, chlore, renfort de 2 épidémiologistes.',
            ]);

            $meeting = $meetings->create([
                'title' => 'Réunion de coordination – riposte choléra',
                'meeting_type' => 'crisis',
                'starts_at' => now()->subDays(2)->setTime(9, 0),
                'ends_at' => now()->subDays(2)->setTime(11, 0),
                'location' => 'Salle de crise COUSP',
                'agenda' => "1. Situation épidémiologique\n2. Logistique\n3. Communication",
                'minutes' => 'La situation progresse dans deux zones. Les recommandations ci-dessous ont été adoptées.',
                'status' => 'held',
                'organization_unit_id' => $unit('SURV')->id,
                'health_event_id' => $event->id,
                'chair_id' => $coord->id,
                'secretary_id' => $chef->id,
            ], $coord);
            $meeting->participants()->createMany([
                ['institution_id' => $cousp->id, 'user_id' => $dir->id, 'attendance' => 'present'],
                ['institution_id' => $cousp->id, 'user_id' => $chef->id, 'attendance' => 'present'],
                ['institution_id' => $cousp->id, 'external_name' => 'Point focal OMS', 'organization' => 'OMS', 'attendance' => 'present'],
            ]);

            $r1 = $meetings->addRecommendation($meeting, [
                'content' => 'Renforcer la surveillance à base communautaire dans les ZS Limete et Masina',
                'expected_result' => 'Liste des relais formés et rapport journalier des alertes',
                'organization_unit_id' => $unit('SURV-DATA')->id, 'responsible_id' => $agent->id,
                'due_at' => now()->addDays(5)->setTime(15, 0), 'priority' => 'high',
            ], $coord);
            $meetings->convertToTask($r1, [], $chef);

            $meetings->addRecommendation($meeting, [
                'content' => 'Produire une note de communication pour les médias',
                'organization_unit_id' => $unit('COMM')->id, 'due_at' => now()->addDays(3)->setTime(12, 0),
            ], $coord);
            $meetings->addRecommendation($meeting, [
                'content' => 'Évaluer les stocks de SRO et de chlore', 'organization_unit_id' => $unit('LOG')->id,
                'due_at' => now()->subDay()->setTime(16, 0), 'priority' => 'critical',
            ], $coord);

            $missions->create([
                'title' => 'Investigation des cas suspects',
                'destination' => 'ZS Limete',
                'objectives' => "Investiguer les cas suspects, prélever des échantillons et appuyer l'équipe de la zone.",
                'departure_at' => now()->addDay()->setTime(8, 0)->toIso8601String(),
                'return_at' => now()->addDays(3)->setTime(17, 0)->toIso8601String(),
                'organization_unit_id' => $unit('SURV-DATA')->id,
                'health_event_id' => $event->id,
                'mission_leader_id' => $agent->id,
            ], $chef);
        });
    }
}
