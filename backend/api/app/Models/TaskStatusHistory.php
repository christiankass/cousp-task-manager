<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusHistory extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $table = 'task_status_history';

    public $timestamps = false;

    protected $fillable = ['institution_id', 'task_id', 'event', 'previous_status', 'new_status', 'changed_by', 'reason', 'details', 'changed_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'changed_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
