<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UserUnitMembership extends Pivot
{
    use BelongsToInstitution, HasUuids;

    protected $table = 'user_unit_memberships';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['institution_id', 'user_id', 'organization_unit_id', 'membership_type', 'is_primary', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'started_at' => 'date',
            'ended_at' => 'date',
        ];
    }
}
