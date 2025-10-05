<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use App\Http\Middleware\CheckUserRole;
use App\Http\Middleware\EnsureEmailIsVerified;

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
        $this->app['router']->aliasMiddleware('check.user.role', CheckUserRole::class);
        $this->app['router']->aliasMiddleware('verified', EnsureEmailIsVerified::class);
    }
}
