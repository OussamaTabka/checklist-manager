<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserInvitationController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\VersionItemController;
use App\Http\Controllers\Api\ProjectVersionController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\TestRunController;
use App\Http\Controllers\Api\TestCaseRunController;
use App\Http\Controllers\TestResultController;
use App\Http\Controllers\Api\UserStoryController;
Route::post('/test-results', [TestResultController::class, 'updateResults'])->middleware('throttle:60,1');
Route::get('/test-agent/health', [TestResultController::class, 'checkHealth'])->middleware('throttle:60,1');

Route::middleware([\Illuminate\Session\Middleware\StartSession::class])->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset-link');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::get('/invitations/validate', [InvitationController::class, 'validateInvitation'])->middleware('throttle:invitation-validate');
    Route::post('/invitations/accept', [InvitationController::class, 'acceptInvitation'])->middleware('throttle:invitation-accept');

    Route::middleware('auth:sanctum')->group(function () {
        // ==========================================
        // AUTHENTIFICATION - Accessible à tous
        // ==========================================
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/token', [AuthController::class, 'getToken']); // Get API token for authenticated user

        // ==========================================
        // SYSTEM DATA - Accessible to all authenticated users
        // ==========================================
        Route::get('/available-testers', [UserController::class, 'getAvailableTesters']); // Get users available for project assignment

        // ==========================================
        // GESTION UTILISATEURS & SYSTÈME (ADMIN ONLY)
        // ==========================================
        // Users CRUD (ADMINISTRATEUR SYSTÈME ONLY)
        Route::middleware('role:admin')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::get('/users/{user}', [UserController::class, 'show']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::post('/users/{user}/invitations/resend', [UserInvitationController::class, 'resend'])->middleware('throttle:invitation-send');
            Route::post('/users/{user}/invitations/revoke', [UserInvitationController::class, 'revoke'])->middleware('throttle:invitation-send');
        });

        // ==========================================
        // GESTION CHECKLISTS (CHEF & ADMIN_CONTENUS)
        // ==========================================
        Route::middleware(['role:chef|admin_contenus'])->group(function () {
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

        // ==========================================
        // CONSULTATION USER STORIES (CHEF, ADMIN, TESTEUR ASSIGNÉ)
        // ==========================================
        Route::middleware(['role:admin|chef|testeur'])->group(function () {
            Route::get('/projects/{project}/user-stories', [UserStoryController::class, 'index']);
            Route::get('/projects/{project}/user-stories/{userStory}', [UserStoryController::class, 'show']);
            Route::get('/projects/{project}/user-stories/{userStory}/suggest-checklists', [UserStoryController::class, 'suggestChecklists']);
            Route::post('/projects/{project}/user-stories/{userStory}/agent/generate-checklist', [UserStoryController::class, 'generateChecklistWithAgent']);
            Route::post('/projects/{project}/user-stories/{userStory}/generate-from-arxis', [UserStoryController::class, 'generateChecklistFromArxis']);
            Route::post('/projects/{project}/user-stories/{userStory}/attach-checklist', [UserStoryController::class, 'attachChecklist']);
            Route::get('/projects/{project}/user-stories/generators/status', [UserStoryController::class, 'getGeneratorsStatus']);
        });

        // ==========================================
        // GESTION USER STORIES (CHEF ONLY)
        // ==========================================
        Route::middleware(['role:chef'])->group(function () {
            Route::post('/projects/{project}/user-stories', [UserStoryController::class, 'store']);
            Route::put('/projects/{project}/user-stories/{userStory}', [UserStoryController::class, 'update']);
            Route::delete('/projects/{project}/user-stories/{userStory}', [UserStoryController::class, 'destroy']);
            Route::delete('/projects/{project}/user-stories/{userStory}/checklists/{checklistId}', [UserStoryController::class, 'detachChecklist']);
        });

        // ==========================================
        // DASHBOARD & VUE D'ENSEMBLE
        // ==========================================
        Route::middleware(['role:admin|chef|admin_contenus|testeur'])->group(function () {
            Route::get('/dashboard/summary', [\App\Http\Controllers\Api\DashboardController::class, 'summary']);
        });

        // ==========================================
        // GESTION PROJETS (CHEF ONLY)
        // ==========================================
        Route::middleware(['role:chef'])->group(function () {
            Route::get('/projects/metadata', [ProjectController::class, 'metadata']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::put('/projects/{project}', [ProjectController::class, 'update']);
            Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
            Route::post('/projects/{project}/versions', [ProjectController::class, 'createVersion']);
            Route::post('/projects/{project}/assign-testers', [ProjectController::class, 'assignTesters']);
            Route::post('/projects/{project}/assign-checklists', [ProjectController::class, 'assignChecklists']);
            Route::get('/projects/{project}/export/{format?}', [ExportController::class, 'exportProject']);
        });

        // ==========================================
        // VUE PROJETS GÉNÉRALE (TOUS LES RÔLES)
        // ==========================================
        Route::middleware(['role:admin|chef|admin_contenus|testeur'])->group(function () {
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::get('/project-versions/{projectVersion}/export/{format?}', [ExportController::class, 'exportProjectVersion']);
        });

        // ==========================================
        // VUE PROJETS & GESTION POUR LES UTILISATEURS STANDARD
        // ==========================================
        Route::middleware(['role:chef|admin_contenus|testeur'])->group(function () {
            // Get project version with items
            Route::get('/project-versions/{projectVersion}', [ProjectVersionController::class, 'show']);
            
            // Update status & progress
            Route::get('/project-versions/{projectVersion}/progress', [ProjectVersionController::class, 'progress']);
            Route::patch('/version-items/{versionItem}/status', [VersionItemController::class, 'updateStatus']);

            // Test runs (batch ingest for agent/runner)
            Route::post('/test-runs', [TestRunController::class, 'store']);
            Route::post('/test-runs/{run_id}/results', [TestRunController::class, 'storeResults']);
            Route::get('/test-runs/{run_id}', [TestRunController::class, 'show']);

            // Single test-case run flow
            Route::get('/test-cases/{id}', [TestCaseRunController::class, 'show']);
            Route::post('/test-cases/{id}/runs', [TestCaseRunController::class, 'run']);
            
            // Comments routes
            Route::get('/version-items/{versionItem}/comments', [CommentController::class, 'index']);
            Route::post('/version-items/{versionItem}/comments', [CommentController::class, 'store']);
            Route::put('/comments/{comment}', [CommentController::class, 'update']);
            Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
            
            // Change history & traceability
            Route::get('/version-items/{versionItem}/history', [VersionItemController::class, 'getHistory']);
        });
    });
});
