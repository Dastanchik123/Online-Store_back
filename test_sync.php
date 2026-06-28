<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

// Load Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Starting Sync Test...\n";

// 1. Ensure we have a product and a user
$product = Product::first();
if (!$product) {
    echo "Creating dummy product...\n";
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100,
        'sku' => 'TEST-' . Str::random(5),
        'stock_quantity' => 10,
    ]);
}

$user = User::first();
if (!$user) {
    echo "Creating dummy user...\n";
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);
}

echo "Product UUID: {$product->uuid}\n";
echo "User UUID: {$user->uuid}\n";

// 2. Mock request data
$orderUuid = (string) Str::uuid();
$payload = [
    'orders' => [
        [
            'uuid' => $orderUuid,
            'user_uuid' => $user->uuid,
            'total_amount' => 100.00,
            'discount' => 0,
            'payment_method' => 'cash',
            'created_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'product_uuid' => $product->uuid,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 100,
                ]
            ]
        ]
    ]
];

// 3. Call SyncController@push logic
$controller = new \App\Http\Controllers\Api\SyncController();
$request = \Illuminate\Http\Request::create('/api/sync/push', 'POST', $payload);

$response = $controller->push($request);
$data = $response->getData(true);

echo "Response Results:\n";
print_r($data['results']);

// 4. Verify in DB
$order = Order::where('uuid', $orderUuid)->with('items')->first();
if ($order) {
    echo "SUCCESS: Order found in DB with ID {$order->id}\n";
    echo "Order User ID: {$order->user_id} (Expected: {$user->id})\n";
    if ($order->items->count() > 0) {
        $item = $order->items->first();
        echo "Order Item Product ID: {$item->product_id} (Expected: {$product->id})\n";
    } else {
        echo "FAILED: No items found for order\n";
    }
} else {
    echo "FAILED: Order not found in DB\n";
}
