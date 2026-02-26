<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // ✅ routes de test par rôle
    Route::get('/admin-only', fn () => response()->json(['ok' => true, 'area' => 'admin']))
        ->middleware('role:admin');

    Route::get('/chef-only', fn () => response()->json(['ok' => true, 'area' => 'chef']))
        ->middleware('role:chef');

    Route::get('/testeur-only', fn () => response()->json(['ok' => true, 'area' => 'testeur']))
        ->middleware('role:testeur');

    // ✅ Users CRUD (ADMIN ONLY)
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});