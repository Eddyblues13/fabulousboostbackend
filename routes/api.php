<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\LoginController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\SmmAPIController;
use App\Http\Controllers\API\TicketController;
use App\Http\Controllers\API\ServiceController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CurrencyController;
use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\AffiliateController;
use App\Http\Controllers\API\Admin\AdminController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\Admin\SendMailController;
use App\Http\Controllers\API\Admin\AdminAuthController;
use App\Http\Controllers\API\Admin\ManageUserController;
use App\Http\Controllers\API\Admin\ApiProviderController;
use App\Http\Controllers\API\Admin\ManageOrderController;
use App\Http\Controllers\API\Admin\TransactionController;
use App\Http\Controllers\API\Admin\ManageTicketController;
use App\Http\Controllers\API\Admin\AdminSettingsController;
use App\Http\Controllers\API\Admin\ManageServiceController;
use App\Http\Controllers\API\Admin\ManageCategoryController;
use App\Http\Controllers\API\Admin\ManageServicePriceController;
use App\Http\Controllers\API\Admin\ManageTransactionsController;
use App\Http\Controllers\API\Admin\ManageServiceUpdateController;



Route::post('/register', [RegisterController::class, 'register']);
Route::post('/login', [LoginController::class, 'login']);

Route::get('/all-services', [ServiceController::class, 'allServices']);
Route::get('/currencies', [CurrencyController::class, 'fetchCurrencies']);




// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // User routes
    Route::get('/user', [UserController::class, 'user']);
    Route::get('/user/logout', [UserController::class, 'logout']);
    // Categories endpoint
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/all-smm-categories', [CategoryController::class, 'allSmmCategories']);

    // Services endpoint
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/all-smm-services', [ServiceController::class, 'allSmmServices']);
    Route::get('/updates', [ServiceController::class, 'serviceUpdates']);
    // Add this in the protected routes group
    Route::get('/services/search', [ServiceController::class, 'searchServices']);

    // orders endpoint
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/history', [OrderController::class, 'history']);

    // Ticket endpoints
    Route::post('/tickets', [TicketController::class, 'store']);
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::get('/tickets/{id}', [TicketController::class, 'show']);
    Route::post('/tickets/{id}/reply', [TicketController::class, 'reply']);


    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-read/{id}', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/delete/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications/clear-all', [NotificationController::class, 'clearAll']);



    // ServiceUpdate History endpoint
    Route::get('/user-service-updates', [ManageServiceUpdateController::class, 'index']);





    // Account routes
    Route::get('/account', [AccountController::class, 'getAccount']);
    Route::get('/account/notifications', [AccountController::class, 'getNotifications']);
    Route::put('/account/password', [AccountController::class, 'updatePassword']);
    Route::put('/account/email', [AccountController::class, 'updateEmail']);
    Route::put('/account/username', [AccountController::class, 'updateUsername']);
    Route::put('/account/two-factor', [AccountController::class, 'updateTwoFactor']);
    Route::post('/account/API-key', [AccountController::class, 'generateAPIKey']);
    Route::put('/account/preferences', [AccountController::class, 'updatePreferences']);
    Route::put('/account/notifications', [AccountController::class, 'updateNotifications']);


    // Affiliate Program Routes
    Route::get('/affiliate', [AffiliateController::class, 'index']);
    Route::post('/affiliate/generate-link', [AffiliateController::class, 'generateLink']);

    // Affiliate Stats
    Route::get('/affiliate/stats', [AffiliateController::class, 'getStats']);

    // Payouts
    Route::get('/affiliate/payouts', [AffiliateController::class, 'getPayouts']);
    Route::post('/affiliate/request-payout', [AffiliateController::class, 'requestPayout']);

    // Track referral visits
    Route::get('/affiliate/track/{code}', [AffiliateController::class, 'trackVisit'])->withoutMiddleware(['auth:sanctum']);



    // Payment endpoints
    Route::post('/payment/initiate', [PaymentController::class, 'initiatePayment']);
    Route::get('/payment/history', [PaymentController::class, 'paymentHistory']);
});


Route::post('/payment/verify', [PaymentController::class, 'verifyPayment']);
Route::post('/payment/callback', [PaymentController::class, 'handleCallback']);





// routes/API.php
Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            'status' => 'success',
            'message' => 'DB connection working',
            'tables' => DB::select('SHOW TABLES')
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'DB connection failed',
            'message' => $e->getMessage()
        ], 500);
    }
});





Route::prefix('v2')->group(function () {
    // API endpoints - authenticated via API key (handled by VerifyApiKey middleware)
    Route::middleware(['verify.api.key', 'throttle:60,1'])->group(function () {
        // Service list
        Route::post('/services', [SmmAPIController::class, 'getServices']);

        // Order management
        Route::post('/orders', [SmmAPIController::class, 'placeOrder']);
        Route::post('/orders/status', [SmmAPIController::class, 'checkOrderStatus']);
        Route::post('/orders/multi-status', [SmmAPIController::class, 'checkMultiOrderStatus']);
        Route::post('/orders/history', [SmmAPIController::class, 'getOrderHistory']);

        // Refill
        Route::post('/refill', [SmmAPIController::class, 'createRefill']);

        // Balance
        Route::post('/balance', [SmmAPIController::class, 'getBalance']);
    });

    // API key generation (requires Sanctum authentication - user must be logged in)
    Route::post('/generate-key', [SmmAPIController::class, 'generateAPIKey'])
        ->middleware('auth:sanctum');
});




Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'admin.token'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        // send  all user emails
        Route::post('/send-email-all', [SendMailController::class, 'sendEmailToAll']);

        // Admin Settings Endpoints
        Route::get('/settings', [AdminSettingsController::class, 'index']);
        Route::put('/settings/profile', [AdminSettingsController::class, 'updateProfile']);
        Route::put('/settings/security', [AdminSettingsController::class, 'updateSecurity']);
        Route::get('/settings/activity', [AdminSettingsController::class, 'getActivityLogs']);

        // Category management
        Route::get('/categories', [ManageCategoryController::class, 'index']);
        Route::post('/categories', [ManageCategoryController::class, 'store']);
        Route::get('/categories/{id}', [ManageCategoryController::class, 'show']);
        Route::put('/categories/{id}', [ManageCategoryController::class, 'update']);
        Route::delete('/categories/{id}', [ManageCategoryController::class, 'destroy']);
        Route::post('/categories/{id}/activate', [ManageCategoryController::class, 'activate']);
        Route::post('/categories/{id}/deactivate', [ManageCategoryController::class, 'deactivate']);
        Route::post('/categories/deactivate-multiple', [ManageCategoryController::class, 'deactivateMultiple']);

        // Service management
        Route::post('/services', [ManageServiceController::class, 'store']);
        Route::put('/services/{id}', [ManageServiceController::class, 'update']);
        Route::delete('/services/{id}', [ManageServiceController::class, 'destroy']);
        Route::post('/services/{id}/activate', [ManageServiceController::class, 'activate']);
        Route::post('/services/{id}/deactivate', [ManageServiceController::class, 'deactivate']);
        Route::post('/services/deactivate-multiple', [ManageServiceController::class, 'deactivateMultiple']);
        Route::get('/orders', [ManageOrderController::class, 'allOrders']);
        Route::get('/orders/{id}', [ManageOrderController::class, 'show']);


        // API providers
        Route::prefix('providers')->group(function () {

            Route::get('', [APIProviderController::class, 'index']);
            Route::post('', [APIProviderController::class, 'store']);
            Route::get('{id}', [APIProviderController::class, 'show']);
            Route::put('{id}', [APIProviderController::class, 'update']);
            Route::delete('{id}', [APIProviderController::class, 'destroy']);

            Route::patch('/{id}/toggle-status', [APIProviderController::class, 'toggleStatus']);
            Route::post('/{id}/sync-services', [APIProviderController::class, 'syncServices']);

            Route::post('/API-provider/services', [APIProviderController::class, 'getAPIServices']);
            Route::post('/services/import', [APIProviderController::class, 'import']);
            Route::post('/services/import-bulk', [APIProviderController::class, 'importMulti']);
            Route::post('/services/all', [APIProviderController::class, 'fetchAllServicesFromProvider']);
            Route::post('/services/save', [APIProviderController::class, 'importServices']);
        });


        // Manage tickets
        Route::get('/tickets', [ManageTicketController::class, 'index']);
        Route::get('/tickets/{id}', [ManageTicketController::class, 'show']);
        Route::put('/tickets/{id}/status', [ManageTicketController::class, 'updateStatus']);
        Route::put('/tickets/{id}/priority', [ManageTicketController::class, 'updatePriority']);
        Route::post('/tickets/{id}/reply', [ManageTicketController::class, 'reply']);
        Route::delete('/tickets/{id}', [ManageTicketController::class, 'destroy']);

        // Manage Service Controller
        Route::post('/service-updates', [ManageServiceUpdateController::class, 'UpdateService']);
        Route::get('/service-update-history', [ManageServiceUpdateController::class, 'ServiceUpdateHistory']);

        // Transaction Management Routes
        Route::prefix('transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'index']);
            Route::get('/stats', [TransactionController::class, 'stats']);
            Route::post('/', [TransactionController::class, 'store']);
            Route::get('/{transaction}', [TransactionController::class, 'show']);
            Route::put('/{transaction}', [TransactionController::class, 'update']);
            Route::delete('/{transaction}', [TransactionController::class, 'destroy']);
            Route::patch('/{transaction}/status', [TransactionController::class, 'changeStatus']);
        });
    });
});











Route::prefix('admin/users')->middleware(['auth:sanctum', 'admin.token'])->group(function () {

    // User management
    Route::get('/', [ManageUserController::class, 'index']);
    Route::get('/{id}', [ManageUserController::class, 'show']);
    Route::put('/{id}', [ManageUserController::class, 'update']);
    Route::delete('/{id}', [ManageUserController::class, 'destroy']);
    Route::post('/{id}/send-email', [ManageUserController::class, 'sendEmail']);
    Route::post('/balance-adjust', [ManageUserController::class, 'adjust']);
    Route::get('/{id}/orders', [ManageUserController::class, 'getUserOrders']);
    Route::post('/{id}/adjust-balance', [ManageUserController::class, 'adjustBalance']);
    Route::post('/{id}/custom-rate', [ManageUserController::class, 'setCustomRate']);
    Route::post('/{id}/activate', [ManageUserController::class, 'activate']);
    Route::post('/{id}/deactivate', [ManageUserController::class, 'deactivate']);
    Route::patch('/{id}/status', [ManageUserController::class, 'changeStatus']);
    Route::post('/{id}/generate-api-key', [ManageUserController::class, 'generateApiKey']);
    Route::post('/{id}/login-as-user', [ManageUserController::class, 'loginAsUser']);


    // Order management
    Route::get('/{id}/orders', [ManageUserController::class, 'getUserOrders']);
    Route::delete('/orders/{id}', [ManageOrderController::class, 'destroy']);
    Route::put('/orders/{id}', [ManageOrderController::class, 'update']);
    Route::patch('/orders/{id}/status', [ManageOrderController::class, 'updateStatus']);
    Route::get('/categories', [ManageOrderController::class, 'getUserCategories']);
    Route::get('/services', [ManageOrderController::class, 'getUserServices']);


    // Transaction management
    Route::get('/transactions', [ManageTransactionsController::class, 'getAllTransactions']);
    Route::get('/transactions/{id}', [ManageTransactionsController::class, 'getTransaction']);
    Route::put('/transactions/{id}', [ManageTransactionsController::class, 'updateTransaction']);
    Route::delete('/transactions/{id}', [ManageTransactionsController::class, 'deleteTransaction']);
    Route::patch('/transactions/{id}/status', [ManageTransactionsController::class, 'changeStatus']);
    Route::get('/{id}/transactions', [ManageTransactionsController::class, 'getUserTransactions']);
    Route::post('/{id}/transactions', [ManageUserController::class, 'createUserTransaction']);
    Route::put('/{userId}/transactions/{transactionId}', [ManageUserController::class, 'updateUserTransaction']);
    Route::delete('/{userId}/transactions/{transactionId}', [ManageUserController::class, 'deleteUserTransaction']);
});



Route::prefix('admin')->middleware(['auth:sanctum', 'admin.token'])->group(function () {

    // Bulk price management
    Route::post('/services/increase-prices', [ManageServicePriceController::class, 'increasePrices']);
    Route::get('/services/price-stats', [ManageServicePriceController::class, 'getPriceIncreaseStats']);
});
