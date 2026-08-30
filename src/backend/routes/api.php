<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function () {
    // Throttled: these are the only two unauthenticated endpoints that do
    // real work, and login runs a full bcrypt at cost 12 on every call. Left
    // open they are both an unlimited credential-stuffing surface and a cheap
    // CPU-exhaustion DoS — one request costs the attacker nothing and costs
    // the server ~250ms of hashing. 5/minute per IP is generous for a human
    // and useless for a script.
    //
    // Named limiters, one each (defined in AppServiceProvider::boot), rather
    // than a shared `throttle:5,1`: the inline form keys on domain + IP with
    // no route path, so the two endpoints shared a single bucket and failed
    // logins consumed a visitor's ability to register.
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Everything below needs a verified, non-revoked bearer token. The
    // `auth:api` middleware runs the JwtGuard; a null user becomes an
    // AuthenticationException, which ApiExceptionRenderer turns into the
    // UNAUTHENTICATED envelope.
    Route::middleware('auth:api')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'updateMe']);
    });

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::patch('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
});
