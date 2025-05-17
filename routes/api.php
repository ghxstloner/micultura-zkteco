<?php

use App\Http\Controllers\Api\TripulanteApiController;
use Illuminate\Support\Facades\Route;

// Rutas API para gestión de tripulantes y dispositivos ZKTeco
Route::middleware([\App\Http\Middleware\ApiTokenMiddleware::class])->group(function () {
    Route::post('/tripulantes', [TripulanteApiController::class, 'store']);
    Route::post('/tripulantes/sync-devices', [TripulanteApiController::class, 'syncDevices']);
    Route::post('/tripulantes/clear-devices', [TripulanteApiController::class, 'clearDevices']);
});
