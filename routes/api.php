<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerificationCodeController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\OwnerManagementController;
use App\Http\Controllers\OwnerProfileController;
use App\Http\Controllers\SubscriptionPlanController;


// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/send-code',         [VerificationCodeController::class, 'sendCode']); // registration email verification code
    Route::post('/resend-code',        [VerificationCodeController::class, 'resendCode']); // resend registration email verification code
    Route::post('/verify-code',       [VerificationCodeController::class, 'verifyCode']); // registration email verification code
    Route::post('/register/{user}',   [RegistrationController::class, 'register']);

    Route::post('/setup-password/{uuid}', [PasswordSetupController::class, 'setup']); // set password for cafe owner

    Route::post('/login',              [AuthController::class, 'login']); // login 2FA code
    Route::post('/resend-login-code',  [AuthController::class, 'resendLoginCode']); // resend login 2FA code
    Route::post('/verify-login-code', [AuthController::class, 'verifyLoginCode']); // login 2FA code
});

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Admin only
    Route::middleware('role:Admin')->prefix('admin')->group(function () {
        Route::get('/owners',                 [OwnerManagementController::class, 'index']); //list all cafe owners and show only firstname, lastname, status
        Route::get('/owners/{uuid}',          [OwnerManagementController::class, 'show']); //get cafe, branch, status of cafe owner
        Route::patch('/owners/{uuid}/status', [OwnerManagementController::class, 'updateStatus']); //update status of cafe, branch and send email notification to cafe owner
        Route::get('/approvals',              [OwnerManagementController::class, 'approvals']); //get approval list for the cafe owner with status pending_approval (filtered by status parameter)

        Route::get('/subscription-plans',              [SubscriptionPlanController::class, 'index']); // list all subscription plans
        Route::get('/subscription-plans/{uuid}',       [SubscriptionPlanController::class, 'show']); // get subscription plan by uuid
        Route::post('/subscription-plans/create',      [SubscriptionPlanController::class, 'store']); // create subscription plan
        Route::patch('/subscription-plans/{uuid}/update',      [SubscriptionPlanController::class, 'update']); // update subscription plan by uuid
        Route::delete('/subscription-plans/{uuid}/delete',     [SubscriptionPlanController::class, 'destroy']); // delete subscription plan by uuid
        Route::patch('/subscription-plans/{uuid}/restore', [SubscriptionPlanController::class, 'restore']); // restore subscription plan by uuid
    });

    // Cafe Owner only
    Route::middleware('role:Cafe Owner')->prefix('owner')->group(function () {
        Route::get('/profile',         [OwnerProfileController::class, 'profile']);
        Route::get('/cafes',           [OwnerProfileController::class, 'cafes']);
        Route::get('/branches',        [OwnerProfileController::class, 'branches']);
        Route::get('/branches/{uuid}', [OwnerProfileController::class, 'branch']);

        Route::get('/subscription-plans',        [SubscriptionPlanController::class, 'ownerIndex']);
        Route::get('/subscription-plans/{uuid}', [SubscriptionPlanController::class, 'ownerShow']);

        Route::get('/subscription/current', [OwnerProfileController::class, 'currentPlan']);
        Route::get('/subscription/history', [OwnerProfileController::class, 'planHistory']);
    });

    // Manager only
    Route::middleware('role:Manager')->prefix('manager')->group(function () {
        //
    });

    // Cashier only
    Route::middleware('role:Cashier')->prefix('cashier')->group(function () {
        //
    });

    // Admin and Cafe Owner shared
    Route::middleware('role:Admin,Cafe Owner')->group(function () {
        //
    });
});