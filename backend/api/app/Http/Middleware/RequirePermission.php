<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage : ->middleware('permission:users.manage') ; plusieurs codes = l'un d'eux suffit. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $user = $request->user();

        foreach ($codes as $code) {
            if ($user?->hasPermission($code)) {
                return $next($request);
            }
        }

        return ApiResponse::error(__('messages.forbidden'), 'FORBIDDEN', 403);
    }
}
