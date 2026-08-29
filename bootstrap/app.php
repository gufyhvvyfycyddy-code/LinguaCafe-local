<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\RejectWritesDuringRestore;
use App\Http\Responses\MobileApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/review-settings-presets.php',
        ],
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(RejectWritesDuringRestore::class);
        $middleware->preventRequestsDuringMaintenance(['backup-restores/*']);

        if ($trustedProxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'mobile.device' => \App\Http\Middleware\EnsureActiveMobileDevice::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isMobileRequest = fn (Request $request): bool => $request->is('api/v1/mobile/*');

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isMobileRequest) {
            if (! $isMobileRequest($request)) {
                return null;
            }

            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The request data is invalid.',
                422,
                $exception->errors(),
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isMobileRequest) {
            if (! $isMobileRequest($request)) {
                return null;
            }

            return MobileApiResponse::error(
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($isMobileRequest) {
            if (! $isMobileRequest($request)) {
                return null;
            }

            $status = $exception->getStatusCode();
            $code = match ($status) {
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                429 => 'RATE_LIMITED',
                default => 'HTTP_ERROR',
            };

            return MobileApiResponse::error(
                $code,
                $status >= 500 ? 'The server could not complete the request.' : 'The request could not be completed.',
                $status,
            );
        });

        $exceptions->render(function (\Throwable $exception, Request $request) use ($isMobileRequest) {
            if (! $isMobileRequest($request)) {
                return null;
            }

            Log::error('Unhandled mobile API exception.', [
                'exception' => get_class($exception),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
                'request_id' => $request->header('X-Request-Id'),
            ]);

            return MobileApiResponse::error(
                'INTERNAL_ERROR',
                'The server could not complete the request.',
                500,
            );
        });
    })->create();
