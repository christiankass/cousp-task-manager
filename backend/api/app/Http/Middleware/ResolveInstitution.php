<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'institution active est celle liée au jeton de connexion : le client ne peut pas
 * la choisir librement. Changer d'institution impose une nouvelle connexion.
 */
class ResolveInstitution
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $institutionId = $token?->institution_id ?? null;

        $institution = $institutionId ? Institution::query()->find($institutionId) : null;

        if (! $institution || ! $institution->isActive() || ! $user->isActive() || ! $user->isMemberOf($institution)) {
            return ApiResponse::error(__('messages.institution_forbidden'), 'INSTITUTION_FORBIDDEN', 403);
        }

        $this->context->set($institution);

        // La langue choisie par l'utilisateur prime sur celle du navigateur.
        $locale = $user->preferred_language ?: $institution->default_language;
        if (in_array($locale, SetLocale::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
