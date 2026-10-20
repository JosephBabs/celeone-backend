<?php

use App\Http\Controllers\Api\V1\PushNotificationController;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([AssignRequestId::class, 'auth:sanctum'])
    ->group(function (): void {
        Route::post('push/register-device', [PushNotificationController::class, 'registerDevice'])->middleware('throttle:60,1');
        Route::post('push/permission-state', [PushNotificationController::class, 'permissionState'])->middleware('throttle:60,1');
        Route::post('push/send', [PushNotificationController::class, 'send'])->middleware('throttle:240,1');
        Route::get('push/inbox', [PushNotificationController::class, 'inbox']);
        Route::post('push/inbox/read', [PushNotificationController::class, 'read']);
        Route::post('push/inbox/read-all', [PushNotificationController::class, 'readAll']);
        Route::post('push/inbox/delete', [PushNotificationController::class, 'delete']);
        Route::post('push/inbox/delete-all', [PushNotificationController::class, 'deleteAll']);
    });
