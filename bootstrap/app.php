<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API error has a stable `code`, like the domain errors (DomainRuleException).
        // The default 404 message names the model class ("No query results for model [App\Models\Invoice]").
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.', 'code' => 'not_found'], 404);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401)
                : null;
        });

        // Policy denials and missing token abilities.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => 'This action is not allowed for your role or token.', 'code' => 'forbidden'], 403)
                : null;
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => 'Too many requests.', 'code' => 'too_many_requests'], 429, $e->getHeaders())
                : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => $e->getMessage(), 'code' => 'validation_failed', 'errors' => $e->errors()], $e->status)
                : null;
        });
    })->create();
