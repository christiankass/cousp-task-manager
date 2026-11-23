<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chaque requête API démarre cloisonnée et sans institution. L'état précédent est
 * restauré à la fin (utile pour les tests, qui partagent la connexion).
 */
class IsolateTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previousInstitution = $this->context->institution();
        $previousBypass = $this->context->bypassing();

        $this->context->setBypass(false);
        $this->context->clear();

        try {
            return $next($request);
        } finally {
            $this->context->setBypass($previousBypass);
            $previousInstitution ? $this->context->set($previousInstitution) : $this->context->clear();
        }
    }
}
