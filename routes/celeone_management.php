<?php

use App\Http\Controllers\Api\V1\Admin\ManagementAuthController;
use App\Http\Controllers\Api\V1\Admin\ManagementLiveController;
use App\Http\Controllers\Api\V1\Admin\ManagementResourceController;
use App\Http\Controllers\Api\V1\Admin\ManagementStorageController;
use App\Http\Controllers\Api\V1\Admin\ManagementSystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin/management')->group(function () {
    Route::post('/auth/login', [ManagementAuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::get('/media-assets/{asset}/download', [ManagementStorageController::class, 'download'])
        ->middleware('signed')
        ->name('management.media.download');

    Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureAdmin::class])->group(function () {
        Route::get('/auth/me', [ManagementAuthController::class, 'me']);
        Route::post('/auth/logout', [ManagementAuthController::class, 'logout']);

        Route::get('/health', [ManagementSystemController::class, 'health']);
        Route::post('/admins/grant-role', [ManagementSystemController::class, 'grantRole']);

        Route::get('/resources/count', [ManagementResourceController::class, 'count']);
        Route::get('/resources', [ManagementResourceController::class, 'index']);
        Route::post('/resources', [ManagementResourceController::class, 'store']);
        Route::get('/resources/{id}', [ManagementResourceController::class, 'show']);
        Route::put('/resources/{id}', [ManagementResourceController::class, 'update']);
        Route::delete('/resources/{id}', [ManagementResourceController::class, 'destroy']);

        Route::post('/uploads', [ManagementStorageController::class, 'store']);
        Route::get('/media-assets', [ManagementStorageController::class, 'index']);
        Route::delete('/media-assets', [ManagementStorageController::class, 'destroy']);
        Route::get('/live/sessions', [ManagementLiveController::class, 'sessions']);
        Route::get('/live/events', [ManagementLiveController::class, 'events']);
        Route::post('/live/{session}/stop', [ManagementLiveController::class, 'stop']);
    });
});
