<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureDefaults();

        RateLimiter::for('arena-join', fn (Request $request) => Limit::perMinute(30)
            ->by($this->arenaRateLimitKey($request)));

        RateLimiter::for('arena-update', fn (Request $request) => Limit::perMinute(1200)
            ->by($this->arenaRateLimitKey($request)));

        RateLimiter::for('arena-leave', fn (Request $request) => Limit::perMinute(60)
            ->by($this->arenaRateLimitKey($request)));
    }

    private function arenaRateLimitKey(Request $request): string
    {
        return $request->ip().'|'.($request->input('client_id') ?: 'anonymous');
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
