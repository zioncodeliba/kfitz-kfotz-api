<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShippingCarrierController;
use App\Http\Controllers\Api\SystemAlertController;

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
});

// Merchant routes (for merchants)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/merchant/profile', [MerchantController::class, 'profile']);
    Route::put('/merchant/profile', [MerchantController::class, 'updateProfile']);
    Route::get('/merchant/dashboard', [MerchantController::class, 'dashboard']);
    
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
