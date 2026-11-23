<?php

namespace App\Models;

use App\Enums\TaskType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalTemplate extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $fillable = ['institution_id', 'name', 'task_type', 'organization_unit_id', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['task_type' => TaskType::class, 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalTemplateStep::class, 'template_id')->orderBy('step_order');
    }
}
