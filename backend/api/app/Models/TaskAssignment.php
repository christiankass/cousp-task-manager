<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignment extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $fillable = ['institution_id', 'task_id', 'user_id', 'assignment_role', 'assigned_by', 'assigned_at', 'accepted_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
