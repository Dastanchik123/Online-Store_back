<?php

// Load Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Str;

$models = ['User', 'Product', 'Category', 'Order', 'OrderItem', 'FinancialTransaction'];

foreach ($models as $m) {
    $class = "App\\Models\\{$m}";
    if (!class_exists($class)) {
        echo "Class {$class} not found, skipping...\n";
        continue;
    }
    
    $items = $class::whereNull('uuid')->get();
    echo "Updating {$m}: found {$items->count()} records\n";
    
    foreach ($items as $item) {
        $item->uuid = (string) Str::uuid();
        $item->save();
    }
}

echo "Done!\n";
