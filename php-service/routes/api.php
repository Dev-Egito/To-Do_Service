<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\CardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;

// Rota de Liveness (Livre e rápida)
Route::get('/health/live', function () {
    return response()->json([
        'status' => 'alive',
        'timestamp' => now()->toIso8601String(),
    ], 200);
});

// Rota de Readiness (Valida Banco de Dados, Redis e Disco via Spatie Health)
Route::get('/health/ready', HealthCheckJsonResultsController::class);

Route::middleware(['auth.jwt'])->group(function () {

    Route::get('/user-check', function (Request $request) {
        return response()->json([
            'message' => 'Token JWT válido!',
            'user' => $request->attributes->get('user'),
        ]);
    });

    // Kanban READ-ONLY (CQRS): mutações ocorrem no Booking Service via RabbitMQ
    Route::apiResource('boards', BoardController::class)->only(['index', 'show']);
    Route::apiResource('cards', CardController::class)->only(['index', 'show']);

});
