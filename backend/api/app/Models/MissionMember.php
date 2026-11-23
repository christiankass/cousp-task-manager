<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionMember extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $fillable = ['institution_id', 'mission_id', 'user_id', 'assignment', 'participation_status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
