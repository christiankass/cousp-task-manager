<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class InstitutionUserMembership extends Pivot
{
    use BelongsToInstitution, HasUuids;

    protected $table = 'institution_user_memberships';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['institution_id', 'user_id', 'status', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
