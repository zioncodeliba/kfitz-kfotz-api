<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Console\Commands\UpdateShipmentStatuses;
use App\Console\Commands\SyncCashcowInventory;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        UpdateShipmentStatuses::class,
        SyncCashcowInventory::class,
    ])
   ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'check.user.role' => \App\Http\Middleware\CheckUserRole::class,
            'log.plugin.access' => \App\Http\Middleware\LogPluginApiAccess::class,
        ]);
    })

    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('shipments:update-statuses')->everyFiveMinutes();
        $schedule->command('cashcow:sync-inventory')->everyTenMinutes();
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
