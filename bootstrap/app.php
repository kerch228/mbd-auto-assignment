<?php

use App\Exceptions\ApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            ApiException::class,
        ]);

        $exceptions->render(function (ApiException $exception, Request $request) {
            return response()->json([
                'code' => $exception->codeName,
                'message' => $exception->getMessage(),
            ], $exception->status);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Requested resource was not found.',
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Requested resource was not found.',
            ], 404);
        });
    })
    ->create();
