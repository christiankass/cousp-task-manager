<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Langue de l'interface (fr/en). */
class SetLocale
{
    public const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // Avant authentification : en-tête Accept-Language. ResolveInstitution applique
        // ensuite la langue préférée de l'utilisateur.
        $locale = $request->headers->has('Accept-Language')
            ? $request->getPreferredLanguage(self::SUPPORTED)
            : config('app.locale');

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'fr');

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
