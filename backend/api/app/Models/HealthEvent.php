<?php

namespace App\Models;

use App\Enums\HealthEventSeverity;
use App\Enums\HealthEventType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Espace de coordination d'une urgence sanitaire (§4.6) ; suit les actions, pas l'épidémiologie. */
class HealthEvent extends Model
{
    use BelongsToInstitution, HasUuids, SoftDeletes;

    public const STATUSES = ['active', 'monitoring', 'closed'];

    protected $fillable = [
        'institution_id', 'reference', 'title', 'event_type', 'description', 'location', 'severity',
        'status', 'start_at', 'end_at', 'closure_note', 'coordinator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => HealthEventType::class,
            'severity' => HealthEventSeverity::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function missions(): HasMany
    {
        return $this->hasMany(OperationalMission::class);
    }

    public function situationReports(): HasMany
    {
        return $this->hasMany(SituationReport::class)->orderByDesc('report_date')->orderByDesc('created_at');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }
}
