<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::post('/organization', [OrganizationController::class, 'store']);
        Route::post('/organization/reparse', [OrganizationController::class, 'reparse']);
        Route::get('/organization/reviews', [OrganizationController::class, 'reviews']);
    });
});

Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$');
