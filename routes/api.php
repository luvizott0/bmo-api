<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CreditCardController;
use App\Http\Controllers\Api\FixedBillController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\StockCategoryController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\WorkspaceInvitationController;
use Illuminate\Support\Facades\Route;

// Public Auth routes
Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Protected routes (Sanctum)
Route::middleware('auth:sanctum')->group(function (): void {
    // Current User & Auth
    Route::prefix('auth')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/password', [AuthController::class, 'changePassword']);
    });

    // Invitations (Accept/Reject)
    Route::post('/invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept']);
    Route::post('/invitations/{token}/reject', [WorkspaceInvitationController::class, 'reject']);

    // Workspaces Management
    Route::apiResource('workspaces', WorkspaceController::class)->except(['destroy']);
    Route::get('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'index']);
    Route::post('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store']);

    // Workspace-scoped Financial Module
    Route::middleware('workspace')->group(function (): void {
        // Categories
        Route::apiResource('categories', CategoryController::class);

        // Bank Accounts
        Route::apiResource('bank-accounts', BankAccountController::class);
        Route::post('/bank-accounts/{bank_account}/adjust-balance', [BankAccountController::class, 'adjustBalance']);

        // Credit Cards
        Route::get('/credit-cards/{credit_card}/monthly-limits', [CreditCardController::class, 'monthlyLimits']);
        Route::apiResource('credit-cards', CreditCardController::class);

        // Transactions
        Route::apiResource('transactions', TransactionController::class);

        // Subscriptions & Split Members
        Route::apiResource('subscriptions', SubscriptionController::class);
        Route::post('/subscriptions/{subscription}/members', [SubscriptionController::class, 'addMember']);
        Route::delete('/subscriptions/{subscription}/members/{member}', [SubscriptionController::class, 'removeMember']);
        Route::post('/subscriptions/{subscription}/members/{member}/payments', [SubscriptionController::class, 'recordPayment']);

        // Fixed Bills & Reminders
        Route::apiResource('fixed-bills', FixedBillController::class);
        Route::post('/fixed-bills/{fixed_bill}/pay', [FixedBillController::class, 'pay']);
        Route::post('/fixed-bills/{fixed_bill}/unpay', [FixedBillController::class, 'unpay']);

        // Stock Categories & Inventory Items
        Route::apiResource('stock-categories', StockCategoryController::class);
        Route::apiResource('inventory-items', InventoryItemController::class);
        Route::post('/inventory-items/{inventory_item}/consume', [InventoryItemController::class, 'consume']);
        Route::post('/inventory-items/{inventory_item}/purchases', [InventoryItemController::class, 'recordPurchase']);
    });
});
