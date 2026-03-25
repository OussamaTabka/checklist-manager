<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\VersionItemController;
use App\Http\Controllers\Api\ProjectVersionController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ExportController;

Route::middleware([\Illuminate\Session\Middleware\StartSession::class])->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Users CRUD (ADMIN ONLY)
        Route::middleware('role:admin')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::get('/users/{user}', [UserController::class, 'show']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
        });


        Route::middleware(['role:admin'])->group(function () {
            Route::get('/checklists', [ChecklistController::class, 'index']);
            Route::post('/checklists', [ChecklistController::class, 'store']);
            Route::get('/checklists/{id}/export/json', [ChecklistController::class, 'exportJson']);
            Route::get('/checklists/{id}/export/csv', [ChecklistController::class, 'exportCsv']);
            Route::get('/checklists/{id}/export/excel', [ChecklistController::class, 'exportExcel']);
            Route::get('/checklists/export/json', [ChecklistController::class, 'exportJson']);
            Route::get('/checklists/export/csv', [ChecklistController::class, 'exportCsv']);
            Route::get('/checklists/export/excel', [ChecklistController::class, 'exportExcel']);
            Route::get('/checklists/items/available', [ChecklistController::class, 'getAvailableItems']);
            Route::get('/checklists/{checklist}', [ChecklistController::class, 'show']);
            Route::put('/checklists/{checklist}', [ChecklistController::class, 'update']);
            Route::delete('/checklists/{checklist}', [ChecklistController::class, 'destroy']);
            Route::patch('/checklists/{checklist}/toggle', [ChecklistController::class, 'toggle']);
        });

        Route::middleware(['role:admin|chef|testeur'])->group(function () {
            Route::get('/dashboard/summary', [\App\Http\Controllers\Api\DashboardController::class, 'summary']);
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::get('/project-versions/{projectVersion}/export/{format?}', [ExportController::class, 'exportProjectVersion']);
        });

        Route::middleware(['role:admin|chef'])->group(function () {
            Route::post('/projects/{project}/versions', [ProjectController::class, 'createVersion']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::put('/projects/{project}', [ProjectController::class, 'update']);
            Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
            
            // Exports
            Route::get('/projects/{project}/export/{format?}', [ExportController::class, 'exportProject']);
        });

        Route::middleware(['role:admin|chef|testeur'])->group(function () {
            Route::patch('/version-items/{versionItem}/status', [VersionItemController::class, 'updateStatus']);
            Route::get('/project-versions/{projectVersion}/progress', [ProjectVersionController::class, 'progress']);
            
            // Comments routes
            Route::get('/version-items/{versionItem}/comments', [CommentController::class, 'index']);
            Route::post('/version-items/{versionItem}/comments', [CommentController::class, 'store']);
            Route::put('/comments/{comment}', [CommentController::class, 'update']);
            Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
        });
    });
});

