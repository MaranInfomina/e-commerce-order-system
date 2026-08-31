<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        $response = self::body($e);

        // Status metadata, not internals. envelope() builds a fresh
        // JsonResponse, which silently discarded every header the exception
        // carried: a 429 lost Retry-After and the three X-RateLimit-* headers
        // (leaving a client no way to know when to try again — Task 10's login
        // page reads Retry-After), and a 405 lost the Allow header the HTTP
        // spec requires on that status. The message and the trace stay
        // suppressed; only the headers come back.
        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                // Never let an exception redefine the media type of an
                // envelope this class just serialised as JSON.
                if (strcasecmp($name, 'Content-Type') === 0) {
                    continue;
                }

                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private static function body(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::envelope(
                'VALIDATION_FAILED',
                'The given data was invalid.',
                422,
                $e->errors(),
            ),

            // Every authentication failure returns the same body: missing,
            // malformed, expired and revoked tokens are indistinguishable
            // to a caller. Distinguishing them tells an attacker which
            // tokens are still live.
            $e instanceof AuthenticationException => self::envelope(
                'UNAUTHENTICATED',
                'Authentication is required to access this resource.',
                401,
            ),

            // The caller is authenticated but lacks the role. Distinct from
            // UNAUTHENTICATED: 401 means "who are you", 403 means "not you".
            $e instanceof AuthorizationException => self::envelope(
                'FORBIDDEN',
                'You do not have permission to perform this action.',
                403,
            ),

            // Laravel's prepareException() ALWAYS converts a statusless
            // AuthorizationException into this Symfony class before render
            // callbacks run, so this is the arm that actually fires over
            // HTTP. The one above is defence for direct callers of
            // ApiExceptionRenderer::render() and cannot be reached through
            // the request pipeline — do not write a feature test for it.
            $e instanceof AccessDeniedHttpException => self::envelope(
                'FORBIDDEN',
                'You do not have permission to perform this action.',
                403,
            ),

            // Route model binding: Laravel wraps ModelNotFoundException in a
            // NotFoundHttpException before render callbacks run, so the
            // previous exception is what tells a missing record from a
            // missing route. This branch must precede the plain 404 branch.
            //
            // Because NOT_FOUND vs ROUTE_NOT_FOUND is derived from that
            // previous exception, controllers must locate records via
            // implicit route-model binding (a typed `Product $product`
            // parameter) rather than `abort(404)` — an explicit abort()
            // never carries a ModelNotFoundException, so it would always
            // fall through to ROUTE_NOT_FOUND regardless of what was
            // actually missing.
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

            $e instanceof ThrottleRequestsException => self::envelope(
                'TOO_MANY_REQUESTS',
                'Too many attempts. Please wait a minute and try again.',
                429,
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
