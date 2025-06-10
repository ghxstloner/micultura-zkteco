<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TripulanteApiController;
use App\Http\Controllers\Api\DispositivosApiController;
use Illuminate\Support\Facades\Route;

// ============================================================================
// RUTAS ORIGINALES PARA DISPOSITIVOS ZKTECO (NO MODIFICADAS)
// ============================================================================

// Rutas API para gestión de tripulantes y dispositivos ZKTeco
Route::middleware([\App\Http\Middleware\ApiTokenMiddleware::class])->group(function () {
    Route::post('/tripulantes', [DispositivosApiController::class, 'store']);
    Route::post('/tripulantes/sync-devices', [DispositivosApiController::class, 'syncDevices']);
    Route::post('/tripulantes/clear-devices', [DispositivosApiController::class, 'clearDevices']);
});

// ============================================================================
// RUTAS PÚBLICAS (SIN AUTENTICACIÓN - PARA REGISTRO Y LOGIN)
// ============================================================================

// Rutas de autenticación públicas
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']); // ← ESTA FALTABA
    Route::post('/check-status', [AuthController::class, 'checkStatus']);
});

// Posiciones disponibles (necesario para el registro)
Route::get('/posiciones', [AuthController::class, 'posiciones']); // Cambié el controlador

// ============================================================================
// RUTAS PROTEGIDAS POR SANCTUM (REQUIEREN AUTENTICACIÓN)
// ============================================================================

Route::middleware(['auth:sanctum'])->group(function () {

    // === Rutas de Autenticación Protegidas ===
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // === Rutas de Gestión de Tripulantes (CRUD) ===
    Route::prefix('crew')->group(function () {
        // CRUD básico de tripulantes
        Route::get('/', [TripulanteApiController::class, 'index']);
        Route::post('/', [TripulanteApiController::class, 'store']);
        Route::get('/{id}', [TripulanteApiController::class, 'show']);
        Route::put('/{id}', [TripulanteApiController::class, 'update']);
        Route::delete('/{id}', [TripulanteApiController::class, 'destroy']);
    });

});