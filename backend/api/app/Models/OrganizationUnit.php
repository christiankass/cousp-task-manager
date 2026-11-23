<?php

namespace App\Models;

use App\Enums\UnitType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationUnit extends Model
{
    use BelongsToInstitution, HasFactory, HasUuids;

    protected $fillable = ['institution_id', 'parent_id', 'name', 'code', 'unit_type', 'description', 'manager_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'unit_type' => UnitType::class,
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_unit_memberships')
            ->using(UserUnitMembership::class)
            ->withPivot(['id', 'institution_id', 'membership_type', 'is_primary', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    /**
     * Identifiants de cette structure et de toutes ses descendantes.
     *
     * @return list<string>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = self::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /** Vrai si $candidate est cette structure ou l'une de ses descendantes. */
    public function isSelfOrAncestorOf(self|string $candidate): bool
    {
        $candidateId = $candidate instanceof self ? $candidate->id : $candidate;

        return in_array($candidateId, $this->descendantIds(), true);
    }
}
