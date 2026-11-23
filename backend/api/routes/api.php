<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\OrganizationUnitController;
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
    });
});
