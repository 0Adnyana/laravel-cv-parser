<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();
        $this->configureDefaults();
    }

    /**
     * Configure trusted proxies from cached configuration so HTTPS asset URLs
     * are generated correctly behind a reverse proxy after config:cache.
     */
    protected function configureTrustedProxies(): void
    {
        $trustedProxies = config('app.trusted_proxies');

        if (! $trustedProxies) {
            return;
        }

        TrustProxies::at(
            $trustedProxies === '*'
                ? '*'
                : array_map(trim(...), explode(',', $trustedProxies)),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
