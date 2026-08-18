<?php

namespace Tests\Feature;

use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSaleTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        RolePermission::create(['role' => 'cashier', 'permission' => 'pos.access']);

        return User::factory()->create(['role' => 'cashier']);
    }

    public function test_pos_sale_decrements_stock_and_creates_order_and_financial_transaction()
    {
        $cashier = $this->cashier();
        $product = Product::factory()->create([
            'stock_quantity' => 10,
            'price'          => 100,
            'sale_price'     => null,
        ]);

        $response = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'price' => 100],
            ],
            'cash_amount'     => 300,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(201);

        $product->refresh();
        $this->assertEquals(7, (float) $product->stock_quantity);
        $this->assertTrue((bool) $product->in_stock);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals(1, $order->items()->count());
        $this->assertEquals(300, (float) $order->total);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals($cashier->id, $order->staff_id);

        $this->assertDatabaseHas('financial_transactions', [
            'trackable_type' => Order::class,
            'trackable_id'   => $order->id,
            'type'           => 'income',
            'amount'         => 300,
        ]);
    }

    public function test_pos_sale_selling_below_purchase_price_is_rejected()
    {
        $cashier = $this->cashier();
        $product = Product::factory()->create([
            'stock_quantity' => 10,
            'purchase_price' => 80,
            'price'          => 100,
        ]);

        $response = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items' => [
                // цена продажи (10) ниже себестоимости (80) — контроллер бросает
                // исключение "ниже себестоимости", запрос должен провалиться (500,
                // т.к. контроллер не оборачивает \Exception в структурированный ответ)
                ['product_id' => $product->id, 'quantity' => 1, 'price' => 10],
            ],
            'cash_amount'     => 10,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(500);

        $product->refresh();
        $this->assertEquals(10, (float) $product->stock_quantity, 'Остаток не должен списываться при отклонённой продаже');
        $this->assertEquals(0, Order::count());
    }

    /**
     * ВНИМАНИЕ: в PosController::store нет проверки "quantity <= stock_quantity"
     * перед decrement(). Это сделано намеренно (не баг): подтверждено
     * пользователем — документируем текущее поведение, а не выдумываем
     * защиту, которой не должно быть.
     */
    public function test_pos_sale_allows_overselling_beyond_stock_current_behavior()
    {
        $cashier = $this->cashier();
        $product = Product::factory()->create([
            'stock_quantity' => 2,
            'price'          => 100,
        ]);

        $response = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'price' => 100],
            ],
            'cash_amount'     => 500,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(201);

        $product->refresh();
        $this->assertEquals(-3, (float) $product->stock_quantity, 'Текущее поведение: остаток уходит в минус, проверки нет');
    }

    public function test_pos_sales_route_requires_pos_or_cashier_permission()
    {
        $user = User::factory()->create(['role' => 'user']);
        $product = Product::factory()->create(['stock_quantity' => 10]);

        $response = $this->actingAs($user)->postJson('/api/pos/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'price' => 100],
            ],
            'cash_amount'     => 100,
            'transfer_amount' => 0,
        ]);

        $response->assertStatus(403);
    }
}
