<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quand MFA_ENFORCE_PRIVILEGED est actif, un compte privilégié sans double authentification
 * ne peut utiliser que les routes de son propre compte (pour l'activer).
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('cousp.mfa_enforce_privileged') && $user && ! $user->hasTwoFactor()
            && ! $request->is('api/v1/auth/*') && $user->isPrivileged()) {
            return ApiResponse::error(__('auth.mfa_setup_required'), 'MFA_SETUP_REQUIRED', 403);
        }

        return $next($request);
    }
}
