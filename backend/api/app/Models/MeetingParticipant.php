<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingParticipant extends Model
{
    use BelongsToInstitution, HasUuids;

    public const ATTENDANCE = ['invited', 'present', 'absent', 'excused'];

    protected $fillable = ['institution_id', 'meeting_id', 'user_id', 'external_name', 'organization', 'attendance'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
