<?php

namespace App\Models;

use App\Enums\ApproverType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApprovalTemplateStep extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['template_id', 'step_order', 'approver_type', 'approver_role_id', 'approver_user_id', 'is_required'];

    protected function casts(): array
    {
        return ['approver_type' => ApproverType::class, 'is_required' => 'boolean'];
    }
}
