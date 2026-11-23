<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modèle de tâche répétée : chaque occurrence devient une tâche ordinaire (§4.2, §4.4). */
class TaskRecurrence extends Model
{
    use BelongsToInstitution, HasUuids;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    protected $fillable = [
        'institution_id', 'organization_unit_id', 'title', 'description', 'expected_result', 'task_type', 'priority',
        'responsible_id', 'collaborator_ids', 'frequency', 'interval', 'weekdays', 'day_of_month', 'due_time',
        'lead_days', 'starts_on', 'ends_on', 'next_occurrence_on', 'last_generated_at', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'task_type' => TaskType::class,
            'priority' => TaskPriority::class,
            'collaborator_ids' => 'array',
            'weekdays' => 'array',
            'interval' => 'integer',
            'day_of_month' => 'integer',
            'lead_days' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_occurrence_on' => 'date',
            'last_generated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'recurrence_id');
    }
}
