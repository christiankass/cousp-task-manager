<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Décision de réunion. Elle se transforme en une ou plusieurs tâches (§6.3) ;
 * son état d'exécution se déduit de ces tâches.
 */
class Recommendation extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $fillable = [
        'institution_id', 'meeting_id', 'organization_unit_id', 'number', 'reference', 'content',
        'expected_result', 'responsible_id', 'due_at', 'priority', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'priority' => TaskPriority::class,
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * not_converted : aucune tâche ; in_progress : au moins une tâche ouverte ;
     * done : toutes les tâches validées ; overdue : une tâche ouverte a dépassé son échéance ;
     * cancelled : abandonnée en réunion.
     */
    public function executionStatus(): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        $tasks = $this->tasks;
        if ($tasks->isEmpty()) {
            return $this->due_at?->isPast() ? 'overdue' : 'not_converted';
        }
        if ($tasks->every(fn (Task $t) => $t->status === TaskStatus::Validated)) {
            return 'done';
        }

        return $tasks->contains(fn (Task $t) => $t->isOverdue()) ? 'overdue' : 'in_progress';
    }
}
