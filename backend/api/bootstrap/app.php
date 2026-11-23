<?php

use App\Http\Middleware\IsolateTenant;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveInstitution;
use App\Http\Middleware\SetLocale;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [IsolateTenant::class, SetLocale::class]);

        $middleware->alias([
            'institution' => ResolveInstitution::class,
            'permission' => RequirePermission::class,
            'platform.admin' => RequirePlatformAdmin::class,
            'mfa' => RequireTwoFactor::class,
        ]);

        // Le contexte institutionnel doit exister avant la résolution des modèles de route,
        // afin qu'un identifiant d'une autre institution donne 404.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveInstitution::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequirePlatformAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(__('messages.validation_error'), 'VALIDATION_ERROR', 422, $e->errors()),
                $e instanceof AuthenticationException => ApiResponse::error(__('messages.unauthenticated'), 'UNAUTHENTICATED', 401),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(__('messages.not_found'), 'NOT_FOUND', 404),
                $e instanceof AccessDeniedHttpException => ApiResponse::error(__('messages.forbidden'), 'FORBIDDEN', 403),
                $e instanceof ThrottleRequestsException => ApiResponse::error(__('messages.too_many_requests'), 'TOO_MANY_REQUESTS', 429),
                $e instanceof HttpExceptionInterface => ApiResponse::error($e->getMessage() ?: __('messages.server_error'), 'HTTP_ERROR', $e->getStatusCode()),
                config('app.debug') => null,
                default => ApiResponse::error(__('messages.server_error'), 'SERVER_ERROR', 500),
            };
        });
    })->create();
