<?php

use App\Http\Controllers\Api\TripulanteApiController;
use Illuminate\Support\Facades\Route;

// Rutas API para gestión de tripulantes y dispositivos ZKTeco
Route::post('/tripulantes', [TripulanteApiController::class, 'store']);
Route::post('/tripulantes/sync-devices', [TripulanteApiController::class, 'syncDevices']);
Route::post('/tripulantes/clear-devices', [TripulanteApiController::class, 'clearDevices']);