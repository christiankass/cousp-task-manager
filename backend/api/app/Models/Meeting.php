<?php

namespace App\Models;

use App\Enums\MeetingType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use BelongsToInstitution, HasUuids, SoftDeletes;

    public const STATUSES = ['planned', 'held', 'cancelled'];

    protected $fillable = [
        'institution_id', 'organization_unit_id', 'health_event_id', 'reference', 'title', 'meeting_type',
        'starts_at', 'ends_at', 'location', 'agenda', 'minutes', 'status', 'chair_id', 'secretary_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'meeting_type' => MeetingType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function healthEvent(): BelongsTo
    {
        return $this->belongsTo(HealthEvent::class);
    }

    public function chair(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chair_id');
    }

    public function secretary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secretary_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class)->orderBy('number');
    }
}
