<?php

namespace App\Services\Tasks;

use App\Models\Institution;
use App\Models\Task;
use App\Models\TaskRecurrence;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calcul des occurrences et création des tâches récurrentes.
 *
 * Une occurrence est la date d'échéance d'une tâche. Elle est créée `lead_days` jours avant,
 * à l'heure de l'institution, puis suit le circuit habituel (notifications, retards, validation).
 */
class RecurrenceService
{
    public const MAX_PER_RUN = 10;

    public function __construct(private TenantContext $context, private TaskService $tasks, private AuditLogger $audit) {}

    /** Première occurrence le `$from` ou après, ou null si la série est terminée. */
    public function nextOccurrence(TaskRecurrence $r, CarbonImmutable $from): ?CarbonImmutable
    {
        $start = CarbonImmutable::parse($r->starts_on->toDateString());
        $from = $from->startOfDay()->max($start);
        $end = $r->ends_on ? CarbonImmutable::parse($r->ends_on->toDateString()) : null;
        $interval = max(1, $r->interval);

        $date = match ($r->frequency) {
            'daily' => $start->addDays((int) ceil($start->diffInDays($from) / $interval) * $interval),
            'weekly' => $this->nextWeekly($r, $start, $from, $interval),
            'monthly' => $this->nextMonthly($r, $start, $from, $interval),
        };

        return $date && (! $end || $date->lte($end)) ? $date : null;
    }

    /** @return list<string> les prochaines dates (aperçu à l'écran) */
    public function preview(TaskRecurrence $r, int $count = 5): array
    {
        $dates = [];
        $cursor = $r->next_occurrence_on ? CarbonImmutable::parse($r->next_occurrence_on->toDateString()) : $this->nextOccurrence($r, $this->today($r));
        while ($cursor && count($dates) < $count) {
            $dates[] = $cursor->toDateString();
            $cursor = $this->nextOccurrence($r, $cursor->addDay());
        }

        return $dates;
    }

    public function today(TaskRecurrence|Institution $from): CarbonImmutable
    {
        $institution = $from instanceof Institution ? $from : $this->context->institution() ?? $from->institution;

        // Date du jour dans le fuseau de l'institution, exprimée comme les autres dates de série (minuit, fuseau de l'application).
        return CarbonImmutable::parse(CarbonImmutable::now($institution->timezone ?: config('app.timezone'))->toDateString());
    }

    /** Positionne la prochaine occurrence à partir d'aujourd'hui (création, reprise, modification). */
    public function schedule(TaskRecurrence $r): TaskRecurrence
    {
        $r->next_occurrence_on = $r->is_active ? $this->nextOccurrence($r, $this->today($r))?->toDateString() : null;
        $r->save();

        return $r;
    }

    /** Crée les tâches arrivées à leur date de création pour une institution. Renvoie le nombre créé. */
    public function generateDue(Institution $institution): int
    {
        return $this->context->runAs($institution, function () use ($institution) {
            $today = $this->today($institution);
            $created = 0;

            $due = TaskRecurrence::query()->where('is_active', true)->whereNotNull('next_occurrence_on')
                ->whereRaw('next_occurrence_on - lead_days <= ?', [$today->toDateString()])
                ->get();

            foreach ($due as $recurrence) {
                $created += $this->generateFor($recurrence, $today);
            }

            return $created;
        });
    }

    private function generateFor(TaskRecurrence $r, CarbonImmutable $today): int
    {
        $created = 0;
        $occurrence = CarbonImmutable::parse($r->next_occurrence_on->toDateString());

        while ($occurrence && $occurrence->subDays($r->lead_days)->lte($today) && $created < self::MAX_PER_RUN) {
            // Après une interruption, seule la dernière occurrence arrivée à sa date de création est créée.
            $following = $this->nextOccurrence($r, $occurrence->addDay());
            if ($following && $following->subDays($r->lead_days)->lte($today)) {
                $occurrence = $following;

                continue;
            }

            if ($this->createTask($r, $occurrence)) {
                $created++;
            }
            $occurrence = $following;
        }

        $r->forceFill(['next_occurrence_on' => $occurrence?->toDateString(), 'last_generated_at' => now()]);
        if (! $occurrence) {
            $r->is_active = false;
        }
        $r->save();

        return $created;
    }

    private function createTask(TaskRecurrence $r, CarbonImmutable $occurrence): ?Task
    {
        $creator = User::query()->find($r->created_by);
        $tz = $this->context->institution()->timezone ?: config('app.timezone');
        [$h, $m] = array_map('intval', explode(':', substr((string) $r->due_time, 0, 5)));
        $due = CarbonImmutable::parse($occurrence->toDateString(), $tz)->setTime($h, $m);
        $start = CarbonImmutable::parse($occurrence->toDateString(), $tz)->subDays($r->lead_days)->startOfDay()->addHours(7);

        $assignees = collect($r->collaborator_ids ?? [])->reject(fn ($id) => $id === $r->responsible_id)
            ->map(fn ($id) => ['user_id' => $id, 'role' => 'collaborator'])
            ->prepend(['user_id' => $r->responsible_id, 'role' => 'responsible'])->values()->all();

        try {
            return $this->tasks->create([
                'organization_unit_id' => $r->organization_unit_id,
                'title' => $r->title,
                'description' => $r->description,
                'expected_result' => $r->expected_result,
                'task_type' => $r->task_type->value,
                'category' => 'recurring',
                'priority' => $r->priority->value,
                'start_at' => $start->min($due)->toIso8601String(),
                'due_at' => $due->toIso8601String(),
                'recurrence_id' => $r->id,
                'occurrence_on' => $occurrence->toDateString(),
                'assignees' => $assignees,
            ], $creator);
        } catch (UniqueConstraintViolationException) {
            return null; // déjà créée par un passage précédent
        } catch (Throwable $e) {
            // Droits retirés, membre parti… : on journalise sans bloquer les autres séries.
            Log::warning('Tâche récurrente non créée', ['recurrence' => $r->id, 'occurrence' => $occurrence->toDateString(), 'error' => $e->getMessage()]);
            $this->audit->log('task_recurrence.failed', $r, [], ['occurrence' => $occurrence->toDateString(), 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function nextWeekly(TaskRecurrence $r, CarbonImmutable $start, CarbonImmutable $from, int $interval): ?CarbonImmutable
    {
        $days = collect($r->weekdays ?: [$start->isoWeekday()])->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 7)->all();
        $week0 = $start->startOfWeek();

        for ($d = $from, $i = 0; $i < 7 * $interval * 2 + 7; $d = $d->addDay(), $i++) {
            $weeks = intdiv((int) $week0->diffInDays($d->startOfWeek()), 7);
            if ($weeks % $interval === 0 && in_array($d->isoWeekday(), $days, true)) {
                return $d;
            }
        }

        return null;
    }

    private function nextMonthly(TaskRecurrence $r, CarbonImmutable $start, CarbonImmutable $from, int $interval): ?CarbonImmutable
    {
        $day = $r->day_of_month ?: $start->day;
        $month = $start->startOfMonth();

        for ($i = 0; $i < 600; $i += $interval) {
            $m = $month->addMonthsNoOverflow($i);
            $candidate = $m->setDay(min($day, $m->daysInMonth));
            if ($candidate->gte($from) && $candidate->gte($start)) {
                return $candidate;
            }
        }

        return null;
    }
}
