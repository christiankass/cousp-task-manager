<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskComment extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $fillable = ['institution_id', 'task_id', 'user_id', 'content'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
