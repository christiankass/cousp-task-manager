<?php

namespace App\Models\Concerns;

use App\Models\Institution;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Cloisonnement applicatif : filtre toutes les requêtes sur l'institution courante
 * et renseigne automatiquement institution_id à la création.
 */
trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope('institution', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->bypassing()) {
                return;
            }

            if (! $context->has()) {
                // Sans contexte, aucune ligne n'est visible : on échoue fermé.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->qualifyColumn('institution_id'), $context->id());
        });

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (! $model->institution_id) {
                $model->institution_id = $context->id();
            }

            if (! $model->institution_id && ! $context->bypassing()) {
                throw new RuntimeException('Aucune institution active pour créer '.static::class);
            }

            if ($context->has() && ! $context->bypassing() && $model->institution_id !== $context->id()) {
                throw new RuntimeException('Écriture refusée hors de l\'institution active.');
            }
        });
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
