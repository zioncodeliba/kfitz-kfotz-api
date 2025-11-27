<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\MerchantCustomerController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\PluginSiteController;
use App\Http\Controllers\Api\PluginOrderController;
use App\Http\Controllers\Api\PluginCategoryController;
use App\Http\Controllers\Api\PluginProductController;
use App\Http\Controllers\Api\ShippingCarrierController;
use App\Http\Controllers\Api\SystemAlertController;
use App\Http\Controllers\Api\MerchantPaymentController;
use App\Http\Controllers\Api\MerchantPaymentSubmissionController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\EmailLogController;
use App\Http\Controllers\Api\MailBroadcastController;
use App\Http\Controllers\Api\EmailListController;
use App\Http\Controllers\Api\MerchantBannerController;
use App\Http\Controllers\Api\MerchantPopupController;
use App\Http\Controllers\Api\SystemSettingController;

// Auth routes (public)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Public product and category routes
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/tree', [CategoryController::class, 'tree']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{id}', [ProductController::class, 'show'])
    ->whereNumber('id');
Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
Route::get('/products/back-in-stock', [ProductController::class, 'backInStock']);
Route::middleware(['auth:sanctum'])->get('/system-settings/shipping', [SystemSettingController::class, 'getShippingPricing']);

// Email verification routes
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name('verification.verify')
    ->middleware('signed');

// Frontend-friendly verification route
Route::get('/verify-email', [EmailVerificationController::class, 'verifyFromFrontend'])
    ->name('verification.verify.frontend');

// Protected routes
Route::middleware(['auth:sanctum', 'check.user.role:merchant'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    
    // Email verification protected routes
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->name('verification.send');
    Route::get('/email/verification-status', [EmailVerificationController::class, 'status']);
});

// Admin routes
Route::middleware(['auth:sanctum', 'verified', 'check.user.role:admin'])->group(function () {
    Route::get('/admin/dashboard', function () {
        return response()->json(['message' => 'Welcome, admin']);
    });
    Route::get('/admin/dashboard/alerts', [SystemAlertController::class, 'index']);
    Route::get('/admin/orders/summary', [OrderController::class, 'adminSummary']);
    Route::put('/admin/system-settings/shipping', [SystemSettingController::class, 'updateShippingPricing']);
    
    // User management
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    
    // Category management (admin only)
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    
    // Product management (admin only)
    Route::post('/products', [ProductController::class, 'store']);
    // Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
    Route::put('/products/{id}', [ProductController::class, 'update'])
        ->whereNumber('id');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])
        ->whereNumber('id');
    Route::post('/product-images', [ProductController::class, 'uploadImage']);
    
    // Shipping carrier management (admin only)
    Route::get('/shipping-carriers', [ShippingCarrierController::class, 'index']);
    Route::post('/shipping-carriers', [ShippingCarrierController::class, 'store']);
    Route::get('/shipping-carriers/active', [ShippingCarrierController::class, 'active']);
    Route::get('/shipping-carriers/stats', [ShippingCarrierController::class, 'stats']);
    Route::get('/shipping-carriers/{id}', [ShippingCarrierController::class, 'show']);
    Route::put('/shipping-carriers/{id}', [ShippingCarrierController::class, 'update']);
    Route::delete('/shipping-carriers/{id}', [ShippingCarrierController::class, 'destroy']);
    Route::post('/shipping-carriers/{id}/test-connection', [ShippingCarrierController::class, 'testConnection']);
    Route::post('/shipping-carriers/{id}/calculate-cost', [ShippingCarrierController::class, 'calculateCost']);
    Route::get('/merchant/shipping-settings', [MerchantController::class, 'getShippingSettings']);
    Route::put('/merchant/shipping-settings', [MerchantController::class, 'updateShippingSettings']);
    Route::get('/plugin-sites', [PluginSiteController::class, 'index']);
    Route::post('/admin/merchants/{merchant}/payments', [MerchantPaymentController::class, 'store']);
    Route::post('/admin/merchants/{merchant}/payments/approve-submissions', [MerchantPaymentController::class, 'approveFromSubmissions']);
    Route::get('/admin/merchants/{merchant}/payment-submissions/pending', [MerchantPaymentSubmissionController::class, 'pendingForMerchant']);

    // Mail center
    Route::get('/email/templates', [EmailTemplateController::class, 'index']);
    Route::post('/email/templates', [EmailTemplateController::class, 'store']);
    Route::get('/email/templates/{template}', [EmailTemplateController::class, 'show']);
    Route::put('/email/templates/{template}', [EmailTemplateController::class, 'update']);
    Route::post('/email/templates/{template}/send-test', [EmailTemplateController::class, 'sendTest']);
    Route::post('/email/events/trigger', [EmailTemplateController::class, 'trigger']);
    Route::get('/email/logs', [EmailLogController::class, 'index']);
    Route::get('/email/logs/{log}', [EmailLogController::class, 'show']);
    Route::post('/email/broadcast', [MailBroadcastController::class, 'sendEmail']);
    Route::delete('/email/templates/{template}', [EmailTemplateController::class, 'destroy']);
    Route::get('/email/lists', [EmailListController::class, 'index']);
    Route::post('/email/lists', [EmailListController::class, 'store']);
    Route::get('/email/lists/{list}', [EmailListController::class, 'show']);
    Route::put('/email/lists/{list}', [EmailListController::class, 'update']);
    Route::delete('/email/lists/{list}', [EmailListController::class, 'destroy']);
    Route::post('/email/lists/{list}/contacts', [EmailListController::class, 'addContacts']);
    Route::delete('/email/lists/{list}/contacts/{contact}', [EmailListController::class, 'removeContact']);
    
});

Route::middleware(['log.plugin.access', 'auth:sanctum', 'verified'])->group(function () {
    Route::post('/plugin-sites', [PluginSiteController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'verified', 'check.user.role:merchant'])->group(function () {
    Route::post('/merchant/payment-submissions', [MerchantPaymentSubmissionController::class, 'store']);
});

// Order management (authenticated users)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/open', [OrderController::class, 'openOrders']);
    Route::get('/orders/waiting-shipment', [OrderController::class, 'waitingForShipment']);
    Route::get('/orders/closed', [OrderController::class, 'closedOrders']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}', [OrderController::class, 'update']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
    Route::get('/orders/status/{status}', [OrderController::class, 'byStatus']);
    Route::get('/orders/dashboard/stats', [OrderController::class, 'dashboard']);
    Route::get('/orders/dashboard/sales-performance', [OrderController::class, 'salesPerformance']);
    Route::post('/orders/{id}/assign-carrier', [OrderController::class, 'assignCarrier']);
    Route::get('/orders/{id}/available-carriers', [OrderController::class, 'getAvailableCarriers']);
    Route::get('/orders/{id}/shipping-settings', [OrderController::class, 'getShippingSettings']);
    Route::put('/orders/{id}/shipping-settings', [OrderController::class, 'updateShippingSettings']);
    Route::post('/orders/{id}/calculate-shipping-cost', [OrderController::class, 'calculateShippingCost']);
    
    // Merchant management (admin only)
    Route::get('/merchants', [MerchantController::class, 'index']);
    Route::post('/merchants', [MerchantController::class, 'store']);
    Route::get('/merchants/{id}', [MerchantController::class, 'show']);
    Route::put('/merchants/{id}', [MerchantController::class, 'update']);
    Route::delete('/merchants/{id}', [MerchantController::class, 'destroy']);

    Route::get('/merchant/customers', [MerchantCustomerController::class, 'index']);
    Route::post('/merchant/customers', [MerchantCustomerController::class, 'store']);
    Route::post('/merchant/customers/import', [MerchantCustomerController::class, 'import']);
    Route::get('/merchant/customers/{customer}', [MerchantCustomerController::class, 'show'])
        ->whereNumber('customer');
    Route::put('/merchant/customers/{customer}', [MerchantCustomerController::class, 'update'])
        ->whereNumber('customer');
    Route::patch('/merchant/customers/{customer}', [MerchantCustomerController::class, 'update'])
        ->whereNumber('customer');

    Route::get('/merchant-banners', [MerchantBannerController::class, 'index']);
    Route::post('/merchant-banners', [MerchantBannerController::class, 'store']);
    Route::put('/merchant-banners/{merchantBanner}', [MerchantBannerController::class, 'update'])
        ->whereNumber('merchantBanner');
    Route::delete('/merchant-banners/{merchantBanner}', [MerchantBannerController::class, 'destroy'])
        ->whereNumber('merchantBanner');

    Route::get('/merchant/payments/history', [MerchantPaymentController::class, 'history']);
    Route::get('/merchant/payments/monthly-summary', [MerchantPaymentController::class, 'monthlySummary']);

    Route::get('/merchant-popup/settings', [MerchantPopupController::class, 'show']);
    Route::put('/merchant-popup/settings', [MerchantPopupController::class, 'update']);

    Route::apiResource('discounts', DiscountController::class);
});

Route::middleware(['log.plugin.access', 'auth:sanctum', 'verified', 'check.user.role:merchant'])->group(function () {
    Route::get('/plugin/categories', [PluginCategoryController::class, 'index']);
    Route::get('/plugin/categories/{category}', [PluginCategoryController::class, 'show'])
        ->whereNumber('category');
    Route::post('/plugin/orders', [PluginOrderController::class, 'store']);
    Route::get('/plugin/products', [PluginProductController::class, 'index']);
    Route::get('/plugin/products/inventory', [PluginProductController::class, 'inventory']);
    Route::get('/plugin/products/{product}', [PluginProductController::class, 'show'])
        ->whereNumber('product');
    Route::get('/plugin/products/{product}/inventory', [PluginProductController::class, 'productInventory'])
        ->whereNumber('product');
});

// Merchant routes (for merchants)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/merchant/profile', [MerchantController::class, 'profile']);
    Route::put('/merchant/profile', [MerchantController::class, 'updateProfile']);
    Route::get('/merchant/dashboard', [MerchantController::class, 'dashboard']);
    Route::get('/merchant-banners/active', [MerchantBannerController::class, 'active']);
    Route::get('/merchant-popup/active', [MerchantPopupController::class, 'active']);
    
    // Shipment management
    Route::get('/shipments', [ShipmentController::class, 'index']);
    Route::post('/shipments', [ShipmentController::class, 'store']);
    Route::get('/shipments/{id}', [ShipmentController::class, 'show']);
    Route::put('/shipments/{id}', [ShipmentController::class, 'update']);
    Route::delete('/shipments/{id}', [ShipmentController::class, 'destroy']);
    Route::get('/shipments/status/{status}', [ShipmentController::class, 'byStatus']);
    Route::post('/shipments/{id}/tracking-events', [ShipmentController::class, 'addTrackingEvent']);
});

// Public shipment tracking
Route::get('/shipments/track/{trackingNumber}', [ShipmentController::class, 'track']);

// Public shipping carrier routes
Route::get('/shipping-carriers/active', [ShippingCarrierController::class, 'active']);
Route::post('/shipping-carriers/{id}/calculate-cost', [ShippingCarrierController::class, 'calculateCost']);

// Public order shipping calculation
Route::post('/orders/calculate-shipping-cost', [OrderController::class, 'calculateShippingCost']);

Route::get('/product-images/{path}', [ProductController::class, 'serveImage'])
    ->where('path', '.*');
