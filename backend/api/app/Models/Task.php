<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use BelongsToInstitution, HasUuids, SoftDeletes;

    protected $fillable = [
        'institution_id', 'organization_unit_id', 'parent_task_id', 'reference', 'title', 'description',
        'expected_result', 'task_type', 'category', 'priority', 'status', 'approval_status',
        'approval_template_id', 'created_by', 'start_at', 'due_at', 'estimated_hours', 'progress',
    ];

    protected function casts(): array
    {
        return [
            'task_type' => TaskType::class,
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'start_at' => 'datetime',
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'validated_at' => 'datetime',
            'due_soon_notified_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
            'progress' => 'integer',
        ];
    }

    /** RM07 : en retard si l'échéance est passée et la tâche n'est pas validée. */
    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->validated_at === null && $this->due_at->isPast();
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_at')->whereNull('validated_at')->where('due_at', '<', now());
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function responsibleAssignment(): HasOne
    {
        return $this->hasOne(TaskAssignment::class)->where('assignment_role', 'responsible');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class)->orderBy('changed_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(ApprovalWorkflow::class)->orderBy('started_at');
    }

    public function activeWorkflow(): HasOne
    {
        return $this->hasOne(ApprovalWorkflow::class)->where('status', 'pending')->orderByDesc('started_at');
    }

    public function responsibleId(): ?string
    {
        return $this->relationLoaded('assignments')
            ? $this->assignments->firstWhere('assignment_role', 'responsible')?->user_id
            : $this->responsibleAssignment()->value('user_id');
    }

    public function isAssignee(User $user): bool
    {
        return $this->assignments()->where('user_id', $user->id)->exists();
    }
}
