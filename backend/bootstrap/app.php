<?php

use App\Http\Middleware\ResolvePublicTenant;
use App\Http\Middleware\SetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'resolve.public_tenant' => ResolvePublicTenant::class,
            'set.tenant' => SetTenant::class,
        ]);

        $middleware->appendToGroup('web', SetTenant::class);
        $middleware->appendToGroup('api', SetTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
