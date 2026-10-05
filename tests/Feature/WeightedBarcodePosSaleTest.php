<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\WeightedBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeightedBarcodePosSaleTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        RolePermission::create(['role' => 'cashier', 'permission' => 'pos.access']);

        return User::factory()->create(['role' => 'cashier']);
    }

    private function service(): WeightedBarcodeService
    {
        return app(WeightedBarcodeService::class);
    }

    public function test_weighted_sale_recomputes_total_from_catalog_price_ignoring_frontend_price()
    {
        $cashier = $this->cashier();
        $product = Product::factory()->create([
            'is_weighted'    => true,
            'unit'           => 'кг',
            'purchase_price' => 5,
            'price'          => 12.90,
            'sale_price'     => null,
            'stock_quantity' => 100,
        ]);
        $barcode = $this->service()->generate($product, 1.25);

        $response = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items' => [
                // Фронт присылает заведомо неверную цену — сервер обязан
                // её проигнорировать и посчитать от каталожной цены.
                ['product_id' => $product->id, 'quantity' => 1.25, 'price' => 1, 'barcode' => $barcode],
            ],
            'cash_amount'     => 16.13,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(201);

        $order = Order::first();
        $this->assertEqualsWithDelta(16.125, (float) $order->total, 0.01);

        $item = $order->items()->first();
        $this->assertEqualsWithDelta(1.25, (float) $item->quantity, 0.001);
        $this->assertSame('кг', $item->unit);
        $this->assertEqualsWithDelta(12.90, (float) $item->price, 0.01);

        $product->refresh();
        $this->assertEqualsWithDelta(98.75, (float) $product->stock_quantity, 0.001);
    }

    public function test_weighted_sale_rejects_barcode_for_different_product()
    {
        $cashier  = $this->cashier();
        $product  = Product::factory()->create(['is_weighted' => true, 'stock_quantity' => 10]);
        $other    = Product::factory()->create(['is_weighted' => true, 'stock_quantity' => 10]);
        $barcode  = $this->service()->generate($other, 1.0);

        $response = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1.0, 'price' => 10, 'barcode' => $barcode],
            ],
            'cash_amount'     => 10,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(500);
        $this->assertEquals(0, Order::count());
    }

    public function test_weighted_sale_rejects_weight_outside_allowed_range()
    {
        $cashier = $this->cashier();
        $product = Product::factory()->create([
            'is_weighted'    => true,
            'min_weight'     => 0.5,
            'max_weight'     => 2.0,
            'stock_quantity' => 10,
        ]);
        $barcode = $this->service()->generate($product, 3.0);

        $response = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3.0, 'price' => 10, 'barcode' => $barcode],
            ],
            'cash_amount'     => 30,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(500);
        $this->assertEquals(0, Order::count());
    }

    public function test_unit_is_snapshotted_on_order_item_for_regular_products_too()
    {
        $cashier = $this->cashier();
        $product = Product::factory()->create([
            'is_weighted'    => false,
            'unit'           => 'шт',
            'price'          => 100,
            'stock_quantity' => 10,
        ]);

        $response = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'price' => 100],
            ],
            'cash_amount'     => 200,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(201);
        $item = Order::first()->items()->first();
        $this->assertSame('шт', $item->unit);
    }
}
