<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Institution;
use App\Models\OrganizationUnit;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\ApprovalService;
use App\Services\Tasks\TaskService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Quelques tâches de démonstration du COUSP, dans des états variés. */
class DemoTaskSeeder extends Seeder
{
    public function run(TenantContext $context, TaskService $tasks, ApprovalService $approvals): void
    {
        config(['cousp.mail_notifications' => false]);
        $cousp = Institution::query()->where('code', 'COUSP')->firstOrFail();

        $context->runAs($cousp, function () use ($tasks, $approvals) {
            if (Task::query()->exists()) {
                return;
            }

            $u = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
            $unit = fn (string $code) => OrganizationUnit::query()->where('code', $code)->firstOrFail();
            [$coord, $chef, $agent, $dir] = [$u('coordonnateur@cousp.local'), $u('chef.data@cousp.local'), $u('agent@cousp.local'), $u('directeur.surv@cousp.local')];

            $make = fn (User $by, string $unitCode, string $title, string $priority, int $startDays, int $dueDays, string $type = 'administrative', array $extra = []) => $tasks->create([
                'organization_unit_id' => $unit($unitCode)->id,
                'title' => $title,
                'task_type' => $type,
                'priority' => $priority,
                'start_at' => now()->addDays($startDays)->toIso8601String(),
                'due_at' => now()->addDays($dueDays)->setTime(15, 0)->toIso8601String(),
                'assignees' => [['user_id' => $agent->id, 'role' => 'responsible']],
            ] + $extra, $by);

            $make($chef, 'SURV-DATA', 'Préparer le rapport hebdomadaire des activités de surveillance', 'high', 0, 4, extra: ['expected_result' => 'Rapport hebdomadaire validé']);
            $t = $make($chef, 'SURV-DATA', 'Consolider les données de rougeole des zones de santé', 'normal', -6, -1, 'operational');
            $tasks->changeStatus($t, TaskStatus::InProgress, $agent);

            $t = $make($dir, 'SURV-DATA', 'Mettre à jour le tableau de suivi Mpox', 'critical', -3, 2, 'operational');
            $tasks->changeStatus($t, TaskStatus::InProgress, $agent);
            $approvals->submit($t, $agent, 'Tableau mis à jour');

            $t = $make($chef, 'SURV-DATA', 'Analyser la complétude des rapports SIMR', 'normal', -10, -3);
            $approvals->submit($t, $agent);
            $approvals->approve($t, $chef, 'Conforme');

            $t = $make($chef, 'SURV-DATA', 'Vérifier les alertes choléra de la semaine', 'high', -4, 1, 'operational');
            $approvals->submit($t, $agent);
            $approvals->reject($t, $chef, 'Ajouter les alertes du Kasaï');

            $make($coord, 'SURV-DATA', 'Préparer la présentation pour la réunion de coordination', 'low', 1, 7);
        });
    }
}
