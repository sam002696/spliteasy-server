<?php

use App\Http\Controllers\API\V1\AuthController;
use App\Http\Controllers\API\V1\BalanceController;
use App\Http\Controllers\API\V1\ExpenseController;
use App\Http\Controllers\API\V1\GroupController;
use App\Http\Controllers\API\V1\GroupInvitationController;
use App\Http\Controllers\API\V1\HomeController;
use App\Http\Controllers\API\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('home', [HomeController::class, 'index']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

        Route::get('balances', [BalanceController::class, 'index']);
        Route::post('balances/groups/{group}/users/{user}/settle', [BalanceController::class, 'markSettled']);

        Route::get('groups', [GroupController::class, 'index']);
        Route::post('groups', [GroupController::class, 'store']);
        Route::get('groups/{group}', [GroupController::class, 'show']);
        Route::delete('groups/{group}', [GroupController::class, 'destroy']);
        Route::post('groups/{group}/leave', [GroupController::class, 'leave']);
        Route::get('groups/{group}/members', [GroupController::class, 'members']);
        Route::delete('groups/{group}/members/{memberId}', [GroupController::class, 'removeMember']);
        Route::post('groups/{group}/invite', [GroupController::class, 'inviteMember']);
        Route::get('groups/{group}/balances', [GroupController::class, 'balances']);
        Route::get('groups/{group}/expenses', [ExpenseController::class, 'index']);
        Route::post('groups/{group}/expenses', [ExpenseController::class, 'store']);
        Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);

        Route::get('group-invitations/pending', [GroupInvitationController::class, 'pending']);
        Route::post('group-invitations/{invitation}/accept', [GroupInvitationController::class, 'accept']);
        Route::post('group-invitations/{invitation}/reject', [GroupInvitationController::class, 'reject']);
    });
});
