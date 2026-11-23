<?php

use App\Http\Controllers\Api\V1\ApprovalTemplateController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationUnitController;
use App\Http\Controllers\Api\V1\TaskAttachmentController;
use App\Http\Controllers\Api\V1\TaskCommentController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskWorkflowController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    // Routes authentifiées, sans institution active : gestion de plateforme.
    Route::middleware(['auth:sanctum', 'platform.admin'])->prefix('platform')->group(function () {
        Route::apiResource('institutions', InstitutionController::class)->except('destroy');
    });

    // Routes authentifiées dans le contexte de l'institution liée au jeton.
    Route::middleware(['auth:sanctum', 'institution'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/institutions', [AuthController::class, 'institutions']);
        Route::put('auth/preferences', [AuthController::class, 'preferences']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        Route::get('organization-units', [OrganizationUnitController::class, 'index'])->middleware('permission:org_units.view,org_units.manage');
        Route::get('organization-units/{organizationUnit}', [OrganizationUnitController::class, 'show'])->middleware('permission:org_units.view,org_units.manage');
        Route::get('organization-units/{organizationUnit}/members', [OrganizationUnitController::class, 'members'])->middleware('permission:org_units.view,org_units.manage');

        Route::middleware('permission:org_units.manage')->group(function () {
            Route::post('organization-units', [OrganizationUnitController::class, 'store']);
            Route::put('organization-units/{organizationUnit}', [OrganizationUnitController::class, 'update']);
            Route::delete('organization-units/{organizationUnit}', [OrganizationUnitController::class, 'destroy']);
            Route::post('organization-units/{organizationUnit}/members', [OrganizationUnitController::class, 'addMember']);
            Route::delete('organization-units/{organizationUnit}/members/{userId}', [OrganizationUnitController::class, 'removeMember'])->whereUuid('userId');
        });

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view,users.manage');
        Route::get('users/{id}', [UserController::class, 'show'])->whereUuid('id')->middleware('permission:users.view,users.manage');

        Route::middleware('permission:users.manage')->group(function () {
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{id}', [UserController::class, 'update'])->whereUuid('id');
            Route::patch('users/{id}/status', [UserController::class, 'updateStatus'])->whereUuid('id');
        });

        Route::middleware('permission:roles.assign')->group(function () {
            Route::post('users/{id}/roles', [UserController::class, 'assignRole'])->whereUuid('id');
            Route::delete('users/{id}/roles/{assignmentId}', [UserController::class, 'removeRole'])->whereUuid(['id', 'assignmentId']);
        });

        // Tâches : la visibilité fine est appliquée par TaskAccess dans chaque action.
        Route::prefix('tasks')->whereUuid(['id', 'assignmentId', 'attachmentId'])->group(function () {
            Route::get('/', [TaskController::class, 'index']);
            Route::post('/', [TaskController::class, 'store'])->middleware('permission:tasks.create');
            Route::get('{id}', [TaskController::class, 'show']);
            Route::put('{id}', [TaskController::class, 'update']);
            Route::delete('{id}', [TaskController::class, 'destroy']);
            Route::patch('{id}/status', [TaskController::class, 'status']);
            Route::patch('{id}/progress', [TaskController::class, 'progress']);
            Route::post('{id}/assignments', [TaskController::class, 'assign']);
            Route::delete('{id}/assignments/{assignmentId}', [TaskController::class, 'unassign']);
            Route::get('{id}/history', [TaskController::class, 'history']);
            Route::get('{id}/comments', [TaskCommentController::class, 'index']);
            Route::post('{id}/comments', [TaskCommentController::class, 'store']);
            Route::get('{id}/attachments', [TaskAttachmentController::class, 'index']);
            Route::post('{id}/attachments', [TaskAttachmentController::class, 'store']);
            Route::get('{id}/attachments/{attachmentId}', [TaskAttachmentController::class, 'download']);
            Route::post('{id}/submit', [TaskWorkflowController::class, 'submit']);
            Route::get('{id}/approvals', [TaskWorkflowController::class, 'approvals']);
            Route::post('{id}/approve', [TaskWorkflowController::class, 'approve']);
            Route::post('{id}/reject', [TaskWorkflowController::class, 'reject']);
            Route::post('{id}/request-correction', [TaskWorkflowController::class, 'reject']);
        });

        Route::get('approval-templates', [ApprovalTemplateController::class, 'index'])->middleware('permission:workflow.manage,tasks.create');
        Route::post('approval-templates', [ApprovalTemplateController::class, 'store'])->middleware('permission:workflow.manage');
        Route::put('approval-templates/{id}', [ApprovalTemplateController::class, 'update'])->whereUuid('id')->middleware('permission:workflow.manage');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');

        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices', [DeviceController::class, 'destroy']);

        Route::get('dashboard/overview', [DashboardController::class, 'overview']);
        Route::get('dashboard/overdue', [DashboardController::class, 'overdue']);
    });
});
