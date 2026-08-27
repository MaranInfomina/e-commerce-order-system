<?php

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::patch('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
});
