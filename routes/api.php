<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerificationCodeController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\OwnerManagementController;
use App\Http\Controllers\OwnerProfileController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SubscriptionCheckoutController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\NativeSubscriptionController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MenuCategoryController;
use App\Http\Controllers\CategoryBranchController;
use App\Http\Controllers\CafeStaffController;

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
        Route::get('/owners/stats',           [OwnerManagementController::class, 'stats']); //get the total number of cafe owners for each status
        Route::get('/owners',                 [OwnerManagementController::class, 'index']); //list all cafe owners and show only firstname, lastname, status
        Route::get('/owners/{uuid}',          [OwnerManagementController::class, 'show']); //get cafe, branch, status of cafe owner
        Route::patch('/owners/{uuid}/status', [OwnerManagementController::class, 'updateStatus']); //update status of cafe, branch and send email notification to cafe owner
        
        Route::get('/approvals',              [OwnerManagementController::class, 'approvals']); //get approval list for the cafe owner with status pending_approval (filtered by status parameter)
        Route::get('/approvals/stats', [OwnerManagementController::class, 'approvalStats']);

        Route::get('/subscription-plans',              [SubscriptionPlanController::class, 'index']); // list all subscription plans
        Route::get('/subscription-plans/{uuid}',       [SubscriptionPlanController::class, 'show']); // get subscription plan by uuid
        Route::post('/subscription-plans/create',      [SubscriptionPlanController::class, 'store']); // create subscription plan
        Route::patch('/subscription-plans/{uuid}/update',      [SubscriptionPlanController::class, 'update']); // update subscription plan by uuid
        Route::delete('/subscription-plans/{uuid}/delete',     [SubscriptionPlanController::class, 'destroy']); // delete subscription plan by uuid
        Route::patch('/subscription-plans/{uuid}/restore', [SubscriptionPlanController::class, 'restore']); // restore subscription plan by uuid

        Route::get('/subscribers', [SubscriptionController::class, 'subscribers']); // admin - get all subscribers
        Route::get('/owners/{uuid}/subscription-history', [SubscriptionController::class, 'ownerHistory']); // admin - get owner's subscription history

        Route::patch('/branches/{uuid}/status', [OwnerManagementController::class, 'updateBranchStatus']); //approve/reject a single branch (owner already active)
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

        Route::post('/subscriptions/checkout', [SubscriptionCheckoutController::class, 'store']);
        Route::post('/subscriptions/native', [NativeSubscriptionController::class, 'store']); // not working

        Route::post('/branches', [BranchController::class, 'store']); // add side branch

        Route::get('/staff',           [CafeStaffController::class, 'index']);
        Route::post('/staff',          [CafeStaffController::class, 'store']);
        Route::delete('/staff/{uuid}', [CafeStaffController::class, 'destroy']);

        Route::get('/menu-categories',                        [MenuCategoryController::class, 'index']);
        Route::post('/menu-categories',                       [MenuCategoryController::class, 'store']);
        Route::patch('/menu-categories/{uuid}',                [MenuCategoryController::class, 'update']);

        Route::get('/menu-categories/{uuid}/branches',                       [CategoryBranchController::class, 'index']);
        Route::get('/menu-categories/{uuid}/branches-status',                [CategoryBranchController::class, 'status']);
        Route::patch('/menu-categories/{categoryUuid}/branches/{branchUuid}', [CategoryBranchController::class, 'update']);
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
        Route::get('/documents/user/{userDocId}',     [DocumentController::class, 'userDocument']);
        Route::get('/documents/cafe/{cafeDocId}',      [DocumentController::class, 'cafeDocument']);
        Route::get('/documents/branch/{branchDocId}',  [DocumentController::class, 'branchDocument']);
    });
});

Route::get('/branch-picture/{uuid}', [DocumentController::class, 'branchPicture']);

Route::post('/webhooks/paymongo', [PaymentWebhookController::class, 'handle']);