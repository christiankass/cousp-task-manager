<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Services\Tasks\RecurrenceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Crée les tâches des séries récurrentes arrivées à leur date (§4.2 « tâches récurrentes »). */
class GenerateRecurringTasks extends Command
{
    protected $signature = 'tasks:generate-recurring';

    protected $description = 'Crée les occurrences des tâches récurrentes';

    public function handle(TenantContext $context, RecurrenceService $recurrences): int
    {
        $institutions = $context->withoutIsolation(fn () => Institution::query()->where('status', 'active')->get());
        $total = 0;

        foreach ($institutions as $institution) {
            $total += $recurrences->generateDue($institution);
        }

        $this->info("Tâches récurrentes créées : {$total}");

        return self::SUCCESS;
    }
}
