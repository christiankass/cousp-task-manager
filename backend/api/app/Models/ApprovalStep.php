<?php

namespace App\Models;

use App\Enums\ApproverType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStep extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $fillable = [
        'institution_id', 'workflow_id', 'step_order', 'approver_type', 'approver_id', 'approver_role_id',
        'status', 'decision', 'decided_by', 'comments', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['approver_type' => ApproverType::class, 'decided_at' => 'datetime'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
