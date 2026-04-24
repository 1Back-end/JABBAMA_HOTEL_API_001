<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\NoCache;
use App\Http\Middleware\UserPasswordChangeMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function ($schedule) {
        $schedule->command('menu:clear-virtual-temp')
            ->everyMinute()
            ->withoutOverlapping();
    })

    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
        ]);

        $middleware->alias([
            'Excel' => Maatwebsite\Excel\Facades\Excel::class,
        ]);
        $middleware->api([
            EnsureFrontendRequestsAreStateful::class,
            NoCache::class, // ✅ ICI C’EST BON
        ]);

        $middleware->alias([
            'check.permission' => \App\Http\Middleware\CheckPermission::class,
            'user.change_password' => UserPasswordChangeMiddleware::class,
            'auth.system' => \App\Http\Middleware\AuthSystemMiddleware::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
