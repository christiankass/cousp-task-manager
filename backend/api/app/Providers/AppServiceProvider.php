<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Politique de mots de passe robustes (§8.4).
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers()->symbols());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Les commandes artisan (migrations, seeders, tâches planifiées) sont des traitements système.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $this->app->booted(fn () => rescue(fn () => $this->app->make(TenantContext::class)->setBypass(true), report: false));
        }
    }
}
