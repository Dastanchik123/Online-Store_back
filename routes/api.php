<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/generate-sku', [ProductController::class, 'generateSku']);
Route::get('/products/{product}', [ProductController::class, 'show']);

Route::get('/reviews', [ReviewController::class, 'index']);
Route::get('/reviews/{review}', [ReviewController::class, 'show']);

Route::get('/blog', [BlogController::class, 'index']);
Route::get('/blog/{slug}', [BlogController::class, 'show']);

Route::get('/settings/public', [SettingController::class, 'publicSettings']);

Route::get('/banners', [BannerController::class, 'index']);

Route::get('/orders/track/{orderNumber}', [OrderController::class, 'trackByNumber']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/user/profile', [AuthController::class, 'updateProfile']);
    Route::get('/my-debts', [AuthController::class, 'myDebts']);
    Route::get('/my-permissions', [PermissionController::class, 'myPermissions']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle']);

    Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);

    Route::middleware('staff')->group(function () {

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::get('/products/generate-sku', [ProductController::class, 'generateSku']);

        Route::post('/reviews/{review}/approve', [ReviewController::class, 'approve']);

        Route::put('/orders/{order}', [OrderController::class, 'update']);
        Route::post('/orders/{order}/return-items', [OrderController::class, 'returnItems']);

        Route::middleware('admin')->group(function () {
            Route::get('/users/{user}/history', [\App\Http\Controllers\Api\UserController::class, 'history']);
            Route::apiResource('users', \App\Http\Controllers\Api\UserController::class);
        });

        Route::apiResource('suppliers', SupplierController::class);

        Route::apiResource('purchases', PurchaseController::class);
        Route::post('purchases/{purchase}/pay', [PurchaseController::class, 'registerPayment']);

        Route::apiResource('inventory/adjustments', InventoryController::class)->names([
            'index'   => 'inventory.adjustments.index',
            'store'   => 'inventory.adjustments.store',
            'show'    => 'inventory.adjustments.show',
            'update'  => 'inventory.adjustments.update',
            'destroy' => 'inventory.adjustments.destroy',
        ]);

        Route::get('accounting/debts', [AccountingController::class, 'debts']);
        Route::post('accounting/debts/{debt}/pay', [AccountingController::class, 'payDebt']);
        Route::delete('accounting/debts/{debt}', [AccountingController::class, 'deleteDebt']);
        Route::delete('accounting/debts/payments/{payment}', [AccountingController::class, 'deleteDebtPayment']);
        Route::get('accounting/reports', [AccountingController::class, 'reports']);

        Route::get('returns', [ReturnController::class, 'index']);
        Route::get('returns/summary', [ReturnController::class, 'summary']);

        Route::get('reports/reconciliation/{supplier}', [ReportController::class, 'reconciliation']);
        Route::get('reports/purchase/{purchase}', [ReportController::class, 'purchase']);
        Route::get('reports/products', [ReportController::class, 'products']);
        Route::get('reports/products-excel', [ReportController::class, 'productsExcel']);
        Route::get('reports/debts', [ReportController::class, 'debtsPdf']);
        Route::get('reports/debts-excel', [ReportController::class, 'debtsExcel']);
        Route::get('reports/products/{product}/barcode', [ReportController::class, 'barcode']);

        Route::get('/blog-admin/{id}', [BlogController::class, 'adminShow']);
        Route::apiResource('blog-admin', BlogController::class)
            ->except(['index', 'show'])
            ->names('admin.blog')
            ->parameters(['blog-admin' => 'post']);

        Route::middleware('admin')->group(function () {
            Route::get('/settings', [SettingController::class, 'index']);
            Route::post('/settings', [SettingController::class, 'update']);
            Route::post('/settings/upload-file', [SettingController::class, 'uploadFile']);
        });

        Route::apiResource('coupons', CouponController::class);

        Route::apiResource('banners-admin', BannerController::class)
            ->except(['index'])
            ->names('admin.banners')
            ->parameters(['banners-admin' => 'banner']);
        Route::post('/banners/reorder', [BannerController::class, 'reorder']);

        Route::middleware('admin')->group(function () {
            Route::get('/permissions', [PermissionController::class, 'index']);
            Route::get('/permissions/role/{role}', [PermissionController::class, 'getByRole']);
            Route::post('/permissions/role/{role}', [PermissionController::class, 'updateRolePermissions']);
        });

        Route::middleware('admin')->group(function () {
            Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
            Route::apiResource('finances', \App\Http\Controllers\Api\FinancialTransactionController::class);
        });
    });

    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::put('/cart/items/{itemId}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{itemId}', [CartController::class, 'removeItem']);
    Route::delete('/cart', [CartController::class, 'clear']);

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::get('/addresses/{address}', [AddressController::class, 'show']);
    Route::put('/addresses/{address}', [AddressController::class, 'update']);
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::put('/payments/{payment}', [PaymentController::class, 'update']);

    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

    Route::post('/pos/sales', [\App\Http\Controllers\Api\PosController::class, 'store']);
    Route::get('/pos/products/search', [\App\Http\Controllers\Api\PosController::class, 'searchProducts']);
    Route::get('/pos/summary', [\App\Http\Controllers\Api\PosController::class, 'summary']);
    Route::get('/pos/staff', [\App\Http\Controllers\Api\PosController::class, 'getStaff']);
    Route::get('/pos/products', [\App\Http\Controllers\Api\PosController::class, 'getAllProducts']);
    Route::post('/pos/sales/{id}/confirm', [\App\Http\Controllers\Api\PosController::class, 'confirmFinance']);

    Route::get('reports/order/{order}', [ReportController::class, 'order']);
    Route::get('reports/order/{order}/html', [ReportController::class, 'orderHtml']);
    Route::get('reports/order/{order}/thermal', [ReportController::class, 'thermalReceipt']);
    Route::get('reports/order/{order}/thermal/html', [ReportController::class, 'thermalReceiptHtml']);
});
