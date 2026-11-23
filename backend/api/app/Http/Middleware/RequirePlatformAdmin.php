<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Super administrateur de plateforme : rôle distinct de l'administrateur institutionnel (§29.1). */
class RequirePlatformAdmin
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_admin) {
            return ApiResponse::error(__('messages.forbidden'), 'FORBIDDEN', 403);
        }

        return $this->context->withoutIsolation(fn () => $next($request));
    }
}
