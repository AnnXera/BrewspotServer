<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerificationCodeController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\OwnerManagementController;
use App\Http\Controllers\OwnerProfileController;

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
    });

    // Cafe Owner only
    Route::middleware('role:Cafe Owner')->prefix('owner')->group(function () {
        Route::get('/profile',         [OwnerProfileController::class, 'profile']); //get owner profile
        Route::get('/cafes',           [OwnerProfileController::class, 'cafes']); //get cafes owned by this owner
        Route::get('/branches',        [OwnerProfileController::class, 'branches']); //get all branches owned by this owner
        Route::get('/branches/{uuid}', [OwnerProfileController::class, 'branch']); //get specific branch owned by this owner
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