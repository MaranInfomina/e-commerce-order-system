<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductImageController;
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

        // Cart (FR-19, FR-20). auth:api only, no policy: every caller may
        // read and mutate their own cart, and the user id comes from the
        // token, never from the request body.
        Route::get('/cart', [CartController::class, 'show']);
        Route::delete('/cart', [CartController::class, 'clear']);
        Route::post('/cart/items', [CartController::class, 'addItem'])->name('cart.items.store');
        Route::patch('/cart/items/{product}', [CartController::class, 'updateItem']);
        Route::delete('/cart/items/{product}', [CartController::class, 'removeItem']);

        // Admin-only product writes (FR-17). The ProductPolicy makes the
        // role decision from the database row; this middleware only proves
        // who the caller is, so an anonymous write gets 401 and a customer's
        // gets 403.
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        // Product images (FR-23). Inside auth:api so an anonymous caller gets
        // 401 from the Authenticate middleware before any multipart body is
        // parsed; ProductImageRequest::authorize() and the controller's
        // $this->authorize('update', $product) both consult ProductPolicy, so
        // a logged-in customer gets 403.
        Route::post('/products/{product}/image', [ProductImageController::class, 'store']);
        Route::delete('/products/{product}/image', [ProductImageController::class, 'destroy']);

        // Checkout. auth:api only, like the cart routes — the order belongs
        // to whoever is authenticated, never a body-supplied user id.
        Route::post('/orders', [OrderController::class, 'store']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);

        // Admin status advance. Route-model binding gives a plain 404 for a
        // nonexistent id (admins may see every order, so no ownership check
        // is needed here, unlike the customer-facing GET /orders/{id} above).
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    });

    // Public reads — FR-18.
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
});
