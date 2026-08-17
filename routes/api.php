<?php

use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe']);
Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
Route::post('/push/send', [PushNotificationController::class, 'send']);
