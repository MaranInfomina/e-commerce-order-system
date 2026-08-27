<?php

use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    // Remaining product routes are added in Tasks 10 and 11; categories in Task 12.
});
