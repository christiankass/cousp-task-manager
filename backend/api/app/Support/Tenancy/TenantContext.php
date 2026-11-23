<?php

namespace App\Support\Tenancy;

use App\Models\Institution;
use Illuminate\Support\Facades\DB;

/**
 * Contexte institutionnel de la requête courante.
 *
 * Toute donnée appartenant à une institution est filtrée par ce contexte, côté
 * application (scope Eloquent) et côté base (paramètre de session PostgreSQL lu par RLS).
 */
class TenantContext
{
    private ?Institution $institution = null;

    private bool $bypass = false;

    public function set(Institution $institution): void
    {
        $this->institution = $institution;
        $this->syncDatabase();
    }

    public function clear(): void
    {
        $this->institution = null;
        $this->syncDatabase();
    }

    public function institution(): ?Institution
    {
        return $this->institution;
    }

    public function id(): ?string
    {
        return $this->institution?->id;
    }

    public function has(): bool
    {
        return $this->institution !== null;
    }

    public function bypassing(): bool
    {
        return $this->bypass;
    }

    public function setBypass(bool $bypass): void
    {
        $this->bypass = $bypass;
        $this->syncDatabase();
    }

    /**
     * Exécute un traitement système hors du cloisonnement (ex. résolution du compte à la connexion).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutIsolation(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->setBypass(true);

        try {
            return $callback();
        } finally {
            $this->setBypass($previous);
        }
    }

    /**
     * Exécute un traitement dans le contexte d'une institution donnée.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Institution $institution, callable $callback): mixed
    {
        $previous = [$this->institution, $this->bypass];
        $this->institution = $institution;
        $this->bypass = false;
        $this->syncDatabase();

        try {
            return $callback();
        } finally {
            [$this->institution, $this->bypass] = $previous;
            $this->syncDatabase();
        }
    }

    private function syncDatabase(): void
    {
        if (! config('tenancy.rls_enabled') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select("select set_config('app.institution_id', ?, false), set_config('app.bypass_rls', ?, false)", [
            $this->institution?->id ?? '',
            $this->bypass ? 'on' : 'off',
        ]);
    }
}
