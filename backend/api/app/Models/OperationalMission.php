<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mission de terrain. Elle repose sur une tâche opérationnelle : le chef de mission en est
 * responsable, l'équipe y est collaboratrice, et le rapport suit le circuit de validation des tâches.
 */
class OperationalMission extends Model
{
    use BelongsToInstitution, HasUuids, SoftDeletes;

    protected $fillable = [
        'institution_id', 'health_event_id', 'task_id', 'reference', 'title', 'destination', 'objectives',
        'departure_at', 'return_at', 'mission_leader_id', 'report', 'report_submitted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'return_at' => 'datetime',
            'report_submitted_at' => 'datetime',
        ];
    }

    /** planned, in_progress, report_submitted, completed (déduit de la tâche support). */
    public function status(): string
    {
        return match ($this->task?->status) {
            TaskStatus::Todo => 'planned',
            TaskStatus::Submitted => 'report_submitted',
            TaskStatus::Validated => 'completed',
            default => 'in_progress',
        };
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function healthEvent(): BelongsTo
    {
        return $this->belongsTo(HealthEvent::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mission_leader_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(MissionMember::class, 'mission_id');
    }
}
