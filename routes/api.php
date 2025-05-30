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
// NUEVAS RUTAS DE AUTENTICACIÓN (SIN AUTENTICACIÓN REQUERIDA)
// ============================================================================

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// ============================================================================
// NUEVAS RUTAS PROTEGIDAS POR SANCTUM (REQUIEREN AUTENTICACIÓN)
// ============================================================================

Route::middleware(['auth:sanctum'])->group(function () {

    // === Rutas de Autenticación ===
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

    // === Rutas de Gestión de Tripulantes (CRUD) ===
    Route::prefix('crew')->group(function () {
        // CRUD básico de tripulantes
        Route::get('/', [TripulanteApiController::class, 'index']);
        Route::post('/', [TripulanteApiController::class, 'store']);
        Route::get('/{id}', [TripulanteApiController::class, 'show']);
        Route::put('/{id}', [TripulanteApiController::class, 'update']);
        Route::delete('/{id}', [TripulanteApiController::class, 'destroy']);

        // Endpoints adicionales
        Route::get('/posiciones/lista', [TripulanteApiController::class, 'posiciones']);
    });

});