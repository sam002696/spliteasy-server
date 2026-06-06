<?php

use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        channels: __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseService::handleValidationError($exception);
            }
        });

        $exceptions->render(function (AuthenticationException $exception, $request) {
            return ApiResponseService::errorResponse('Invalid or missing authentication token', 401);
        });

        $exceptions->render(function (AuthorizationException $exception, $request) {
            return ApiResponseService::errorResponse('You do not have permission to access this resource', 403);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $exception, $request) {
            return ApiResponseService::errorResponse('Method not allowed for this endpoint', 405);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseService::errorResponse('Resource not found.', 404);
            }
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseService::handleUnexpectedError($exception);
            }
        });
    })->create();
