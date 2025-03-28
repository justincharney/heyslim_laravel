<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Report the exception.
     */
    public function report(): void
    {
        // ...
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $exception): Response
    {
        if ($request->expectsJson()) {
            if ($exception instanceof ValidationException) {
                return response()->json(
                    [
                        "message" => "The given data was invalid.",
                        "errors" => $exception->errors(),
                    ],
                    422
                );
            }

            if ($exception instanceof AuthenticationException) {
                return response()->json(
                    [
                        "message" => "Unauthenticated.",
                        "error" => "authentication_required",
                    ],
                    401
                );
            }

            if ($exception instanceof AuthorizationException) {
                return response()->json(
                    [
                        "message" => "Unauthorized action.",
                        "error" => "insufficient_permissions",
                    ],
                    403
                );
            }

            if ($exception instanceof ModelNotFoundException) {
                return response()->json(
                    [
                        "message" => "Resource not found.",
                        "error" => "resource_not_found",
                    ],
                    404
                );
            }

            if ($exception instanceof HttpException) {
                return response()->json(
                    [
                        "message" => $exception->getMessage() ?: "HTTP error.",
                        "error" => "http_error",
                    ],
                    $exception->getStatusCode()
                );
            }

            // Log unexpected errors
            $this->reportable($exception);

            return response()->json(
                [
                    "message" => "Server error.",
                    "error" => "server_error",
                ],
                500
            );
        }

        return parent::render($request, $exception);
    }
}
