<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::envelope(
                'VALIDATION_FAILED',
                'The given data was invalid.',
                422,
                $e->errors(),
            ),

            // Route model binding: Laravel wraps ModelNotFoundException in a
            // NotFoundHttpException before render callbacks run, so the
            // previous exception is what tells a missing record from a
            // missing route. This branch must precede the plain 404 branch.
            $e instanceof NotFoundHttpException
                && $e->getPrevious() instanceof ModelNotFoundException => self::envelope(
                    'NOT_FOUND',
                    'The requested resource was not found.',
                    404,
                ),

            $e instanceof NotFoundHttpException => self::envelope(
                'ROUTE_NOT_FOUND',
                'The requested endpoint does not exist.',
                404,
            ),

            $e instanceof MethodNotAllowedHttpException => self::envelope(
                'METHOD_NOT_ALLOWED',
                'The HTTP method is not supported for this endpoint.',
                405,
            ),

            // Any other HTTP exception keeps its status but not its message,
            // which may contain internal detail.
            $e instanceof HttpExceptionInterface => self::envelope(
                'HTTP_ERROR',
                'The request could not be completed.',
                $e->getStatusCode(),
            ),

            default => self::envelope(
                'INTERNAL_ERROR',
                'An unexpected error occurred.',
                500,
            ),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $details
     */
    private static function envelope(
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
