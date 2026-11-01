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
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierReturnController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

// Весь /api уже под общим throttle:api (60/мин на юзера или IP, см.
// RouteServiceProvider::configureRateLimiting) — этого достаточно для
// публичного каталога. Для /login и /register этого мало: 60 попыток пароля
// в минуту это по сути отсутствие защиты от подбора, поэтому лимит строже.
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Вход покупателя по номеру телефона и коду из SMS. Лимиты на SMS (на номер
// и IP в час) — в PhoneLoginService; здесь — грубая защита от перебора кода
Route::prefix('auth/phone')->group(function () {
    Route::post('/send-code', [AuthController::class, 'sendPhoneCode'])->middleware('throttle:5,1');
    Route::post('/verify', [AuthController::class, 'verifyPhoneCode'])->middleware('throttle:10,1');
});

Route::get('/ping', \App\Http\Controllers\Api\PingController::class);

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::get('/products', [ProductController::class, 'index']);
// generate-sku — только для персонала (группа products.edit ниже); раньше
// публичный дубль этого роута перехватывал запрос без авторизации.
Route::get('/products/{product}', [ProductController::class, 'show'])->where('product', '(?!generate-sku$)[^/]+');

// Поиск товара по фото (Gemini Vision) — отдельный строгий лимит, т.к. каждый
// запрос дороже обычного каталожного и оплачивается через Gemini API.
Route::middleware('throttle:10,1')->post('/products/recognize-photo', [ProductController::class, 'recognizeByPhoto']);

Route::get('/reviews', [ReviewController::class, 'index']);
Route::get('/reviews/{review}', [ReviewController::class, 'show']);

Route::get('/blog', [BlogController::class, 'index']);
Route::get('/blog/{slug}', [BlogController::class, 'show']);

Route::get('/settings/public', [SettingController::class, 'publicSettings']);

Route::get('/banners', [BannerController::class, 'index']);

Route::get('/orders/track/{orderNumber}', [OrderController::class, 'trackByNumber']);

// Электронная копия чека по QR с бумажного чека — только по uuid заказа
Route::get('/receipts/{uuid}', [\App\Http\Controllers\Api\ReceiptController::class, 'show'])
    ->middleware('throttle:30,1');

// Касса самообслуживания — гость без логина, поэтому вне auth:sanctum.
// Каталог self-service использует существующие публичные /products,
// /categories, /settings/public выше — здесь только то, что специфично
// для self-service: создание заказа, оплата, статус.
Route::prefix('self-service')->middleware('throttle:60,1')->group(function () {
    // Обмен одноразового кода пейринга на постоянный device-токен киоска.
    // Отдельный, более строгий rate-limit — код всего 6 цифр.
    Route::post('/pair', [\App\Http\Controllers\Api\SelfServiceDeviceController::class, 'pair'])
        ->middleware('throttle:10,1');

    // Эти роуты дёргает сама касса — требуют валидный device-токен,
    // выданный через /pair (см. EnsureSelfServiceDevice).
    Route::middleware('self-service.device')->group(function () {
        Route::post('/orders', [\App\Http\Controllers\Api\SelfServiceController::class, 'store']);
        Route::get('/orders/{orderUuid}', [\App\Http\Controllers\Api\SelfServiceController::class, 'show']);
        Route::get('/orders/{orderUuid}/payment-status', [\App\Http\Controllers\Api\SelfServiceController::class, 'paymentStatus']);
        Route::post('/orders/{orderUuid}/cancel', [\App\Http\Controllers\Api\SelfServiceController::class, 'cancel']);
        Route::get('/orders/{orderUuid}/receipt', [\App\Http\Controllers\Api\SelfServiceController::class, 'receipt']);

        // PIN/бейдж сотрудника для удаления товара из корзины — строгий
        // rate-limit против перебора короткого PIN прямо на кассе
        Route::post('/staff-override', [\App\Http\Controllers\Api\SelfServiceStaffController::class, 'authorizeOverride'])
            ->middleware('throttle:10,1');
    });

    // Демо-подтверждение оплаты временным QR (TemporaryQrPaymentProvider):
    // покупатель сам жмёт «Я оплатил». Провайдер работает только в
    // local/testing — на проде этих роутов нет вовсе, оплату подтверждает
    // только подписанный вебхук GoPay.
    if (app()->environment(['local', 'testing'])) {
        Route::get('/pay/{token}', [\App\Http\Controllers\Api\SelfServiceController::class, 'payInfo']);
        Route::post('/pay/{token}/confirm', [\App\Http\Controllers\Api\SelfServiceController::class, 'confirmPayment']);
    }

    // Вебхук GoPay (events_url и legacy callback_url) — подлинность
    // проверяется HMAC-подписью внутри контроллера, поэтому вне auth/device-групп.
    // Адрес сохранён прежним: он уже прописан у GoPay как callback_url.
    Route::post('/gopay/webhook', [\App\Http\Controllers\Api\GoPayWebhookController::class, 'handle'])
        ->withoutMiddleware('throttle:60,1');
});

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

        Route::middleware('permission:categories.manage')->group(function () {
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
        });
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
            ->middleware('permission:categories.delete');

        Route::middleware('permission:products.edit')->group(function () {
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update']);
            Route::get('/products/generate-sku', [ProductController::class, 'generateSku']);
            Route::post('/products/ai-description', [ProductController::class, 'aiDescription']);
            Route::post('/products/{product}/weighted-barcode', [ProductController::class, 'generateWeightedBarcode']);
            // Группы горячих товаров (/admin/hot-products)
            Route::post('/hot-groups', [SettingController::class, 'updateHotGroups']);
            Route::post('/hot-groups/rename', [SettingController::class, 'renameHotGroup']);
            Route::post('/hot-groups/delete', [SettingController::class, 'deleteHotGroup']);
        });
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])
            ->middleware('permission:products.delete');

        Route::post('/reviews/{review}/approve', [ReviewController::class, 'approve']);

        Route::put('/orders/{order}', [OrderController::class, 'update']);
        Route::post('/orders/{order}/return-items', [OrderController::class, 'returnItems']);

        Route::middleware('superadmin')->group(function () {
            Route::get('/users/{user}/history', [\App\Http\Controllers\Api\UserController::class, 'history']);
            Route::apiResource('users', \App\Http\Controllers\Api\UserController::class);
        });

        Route::middleware('permission:suppliers.manage')->group(function () {
            Route::apiResource('suppliers', SupplierController::class)->except(['destroy']);
        });
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])
            ->middleware('permission:suppliers.delete')
            ->name('suppliers.destroy');

        Route::middleware('permission:purchases.manage')->group(function () {
            Route::apiResource('purchases', PurchaseController::class)->except(['destroy']);
            Route::post('purchases/{purchase}/pay', [PurchaseController::class, 'registerPayment']);
            Route::get('purchases/{purchase}/returnable-items', [PurchaseController::class, 'returnableItems']);
        });
        Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])
            ->middleware('permission:purchases.delete')
            ->name('purchases.destroy');

        Route::middleware('permission:supplier_returns.manage')->group(function () {
            Route::apiResource('supplier-returns', SupplierReturnController::class)->except(['destroy']);
            Route::post('supplier-returns/{supplierReturn}/confirm', [SupplierReturnController::class, 'confirm']);
            Route::post('supplier-returns/{supplierReturn}/cancel', [SupplierReturnController::class, 'cancel']);
        });
        Route::delete('/supplier-returns/{supplierReturn}', [SupplierReturnController::class, 'destroy'])
            ->middleware('permission:supplier_returns.delete')
            ->name('supplier-returns.destroy');

        Route::middleware('permission:inventory.manage')->group(function () {
            Route::apiResource('inventory/adjustments', InventoryController::class)->except(['destroy'])->names([
                'index'   => 'inventory.adjustments.index',
                'store'   => 'inventory.adjustments.store',
                'show'    => 'inventory.adjustments.show',
                'update'  => 'inventory.adjustments.update',
            ]);
        });
        Route::delete('/inventory/adjustments/{adjustment}', [InventoryController::class, 'destroy'])
            ->middleware('permission:inventory.delete')
            ->name('inventory.adjustments.destroy');

        Route::middleware('permission:debts.view')->group(function () {
            Route::get('accounting/debts', [AccountingController::class, 'debts']);
            Route::post('accounting/debts/{debt}/pay', [AccountingController::class, 'payDebt']);
            // Погашение безналом — QR GoPay
            Route::post('accounting/debts/{debt}/qr', [AccountingController::class, 'createDebtQr']);
            Route::get('accounting/debts/qr/{payment}', [AccountingController::class, 'debtQrStatus']);
            Route::post('accounting/debts/qr/{payment}/cancel', [AccountingController::class, 'cancelDebtQr']);
        });
        // Удаление долга/платежа — деструктивная операция, требует отдельного
        // права, а не просто "просмотр" (debts.view). См. аудит удаления.
        Route::middleware('permission:debts.manage')->group(function () {
            Route::delete('accounting/debts/{debt}', [AccountingController::class, 'deleteDebt']);
            Route::delete('accounting/debts/payments/{payment}', [AccountingController::class, 'deleteDebtPayment']);
        });
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('accounting/reports', [AccountingController::class, 'reports']);
        });

        Route::middleware('permission:orders.view')->group(function () {
            Route::get('returns', [ReturnController::class, 'index']);
            Route::get('returns/summary', [ReturnController::class, 'summary']);
        });

        // Печать чеков — рутинное действие кассира при любой продаже, прав не требует
        Route::get('reports/order/{order}', [ReportController::class, 'order']);
        Route::get('reports/order/{order}/html', [ReportController::class, 'orderHtml']);
        Route::get('reports/order/{order}/thermal', [ReportController::class, 'thermalReceipt']);
        Route::get('reports/order/{order}/thermal/html', [ReportController::class, 'thermalReceiptHtml']);

        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports/reconciliation/{supplier}', [ReportController::class, 'reconciliation']);
            Route::get('reports/purchase/{purchase}', [ReportController::class, 'purchase']);
            Route::get('reports/products', [ReportController::class, 'products']);
            Route::get('reports/products-excel', [ReportController::class, 'productsExcel']);
            Route::get('reports/debts', [ReportController::class, 'debtsPdf']);
            Route::get('reports/debts-excel', [ReportController::class, 'debtsExcel']);

            // Асинхронная генерация тяжёлых PDF-отчётов (полный каталог/долги) через очередь
            Route::post('reports/exports', [\App\Http\Controllers\Api\ReportExportController::class, 'store']);
            Route::get('reports/exports/{uuid}', [\App\Http\Controllers\Api\ReportExportController::class, 'show']);
            Route::get('reports/exports/{uuid}/download', [\App\Http\Controllers\Api\ReportExportController::class, 'download']);
        });

        Route::middleware('permission:products.view')->group(function () {
            // Очередь печати этикеток (/admin/print-labels) — не только админу
            Route::post('/print-label-queue', [SettingController::class, 'updatePrintQueue']);
            Route::get('reports/products/{product}/barcode', [ReportController::class, 'barcode']);
            Route::post('/barcodes/parse', [\App\Http\Controllers\Api\BarcodeController::class, 'parse']);
        });

        Route::middleware('permission:blog.manage')->group(function () {
            Route::get('/blog-admin/{id}', [BlogController::class, 'adminShow']);
            Route::apiResource('blog-admin', BlogController::class)
                ->except(['index', 'show'])
                ->names('admin.blog')
                ->parameters(['blog-admin' => 'post']);
        });

        Route::middleware('superadmin')->group(function () {
            Route::get('/audit-logs', [\App\Http\Controllers\Api\AuditLogController::class, 'index']);

            Route::get('/settings', [SettingController::class, 'index']);
            Route::post('/settings', [SettingController::class, 'update']);
            Route::post('/settings/upload-file', [SettingController::class, 'uploadFile']);

            Route::get('/self-service-devices', [\App\Http\Controllers\Api\SelfServiceDeviceController::class, 'index']);
            Route::post('/self-service-devices', [\App\Http\Controllers\Api\SelfServiceDeviceController::class, 'store']);
            Route::post('/self-service-devices/{device}/pairing-code', [\App\Http\Controllers\Api\SelfServiceDeviceController::class, 'issuePairingCode']);
            Route::post('/self-service-devices/{device}/revoke', [\App\Http\Controllers\Api\SelfServiceDeviceController::class, 'revoke']);
        });

        Route::middleware('permission:alerts.manage')->group(function () {
            Route::get('/alerts', [\App\Http\Controllers\Api\AlertController::class, 'index']);
            Route::get('/alerts/count', [\App\Http\Controllers\Api\AlertController::class, 'count']);
            Route::post('/alerts/{alert}/resolve', [\App\Http\Controllers\Api\AlertController::class, 'resolve']);
            Route::post('/alerts/{alert}/refund-cash', [\App\Http\Controllers\Api\AlertController::class, 'refundCash']);
        });

        Route::middleware('permission:marketing.manage')->group(function () {
            Route::apiResource('coupons', CouponController::class);

            Route::apiResource('banners-admin', BannerController::class)
                ->except(['index'])
                ->names('admin.banners')
                ->parameters(['banners-admin' => 'banner']);
            Route::post('/banners/reorder', [BannerController::class, 'reorder']);
        });

        Route::middleware('superadmin')->group(function () {
            Route::get('/permissions', [PermissionController::class, 'index']);
            Route::get('/permissions/role/{role}', [PermissionController::class, 'getByRole']);
            Route::post('/permissions/role/{role}', [PermissionController::class, 'updateRolePermissions']);

            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles', [RoleController::class, 'store']);
            Route::put('/roles/{role}', [RoleController::class, 'update']);
            Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        });

        Route::middleware('superadmin')->group(function () {
            Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
            Route::apiResource('finances', \App\Http\Controllers\Api\FinancialTransactionController::class);
        });
    });

    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::put('/cart/items/{itemId}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{itemId}', [CartController::class, 'removeItem']);
    Route::delete('/cart', [CartController::class, 'clear']);
    Route::post('/cart/checkout-qr', [CartController::class, 'checkoutQr']);

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::get('/addresses/{address}', [AddressController::class, 'show']);
    Route::put('/addresses/{address}', [AddressController::class, 'update']);
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/payment-qr', [OrderController::class, 'generatePaymentQr']);

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::put('/payments/{payment}', [PaymentController::class, 'update']);

    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

    Route::middleware('permission:pos.access,cashier.access')->group(function () {
        Route::post('/pos/sales', [\App\Http\Controllers\Api\PosController::class, 'store'])
            ->middleware('idempotency');
        // Продажа с QR-частью ждёт подтверждения банка: статус, отмена, новый QR
        Route::get('/pos/sales/{order}/status', [\App\Http\Controllers\Api\PosController::class, 'saleStatus']);
        Route::post('/pos/sales/{order}/cancel', [\App\Http\Controllers\Api\PosController::class, 'cancelSale']);
        Route::post('/pos/sales/{order}/qr', [\App\Http\Controllers\Api\PosController::class, 'regenerateSaleQr']);
        Route::get('/pos/products/search', [\App\Http\Controllers\Api\PosController::class, 'searchProducts']);
        Route::get('/pos/summary', [\App\Http\Controllers\Api\PosController::class, 'summary']);
        Route::get('/pos/staff', [\App\Http\Controllers\Api\PosController::class, 'getStaff']);
        Route::get('/pos/customers', [\App\Http\Controllers\Api\PosController::class, 'searchCustomers']);
        Route::post('/pos/customers', [\App\Http\Controllers\Api\PosController::class, 'createCustomer']);
        Route::get('/pos/products', [\App\Http\Controllers\Api\PosController::class, 'getAllProducts']);
        Route::post('/pos/scan-checkout', [\App\Http\Controllers\Api\PosController::class, 'scanCheckout']);
    });
});
