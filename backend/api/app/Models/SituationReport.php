<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rapport de situation (sitrep) d'un événement sanitaire. */
class SituationReport extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $table = 'health_event_situation_reports';

    protected $fillable = ['institution_id', 'health_event_id', 'report_date', 'summary', 'actions', 'needs', 'author_id'];

    protected function casts(): array
    {
        return ['report_date' => 'date'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
