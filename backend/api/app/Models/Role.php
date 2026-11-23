<?php

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un rôle sans institution est un rôle système, visible par toutes les institutions.
 * Un rôle avec institution n'est visible que par celle-ci.
 */
class Role extends Model
{
    use HasUuids;

    protected $fillable = ['institution_id', 'name', 'code', 'description'];

    protected static function booted(): void
    {
        static::addGlobalScope('institution', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->bypassing()) {
                return;
            }

            $builder->where(function (Builder $q) use ($context) {
                $q->whereNull('institution_id');

                if ($context->has()) {
                    $q->orWhere('institution_id', $context->id());
                }
            });
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->using(RolePermission::class);
    }
}
