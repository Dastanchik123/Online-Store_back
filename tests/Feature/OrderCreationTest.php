<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_order_from_cart_computes_totals_and_decrements_stock()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'price'          => 150,
            'stock_quantity' => 5,
        ]);

        $cart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id'    => $cart->id,
            'product_id' => $product->id,
            'quantity'   => 2,
            'price'      => 150,
        ]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'shipping_address' => [
                'first_name'      => 'Иван',
                'last_name'       => 'Иванов',
                'country'         => 'KG',
                'city'            => 'Бишкек',
                'address_line_1'  => 'ул. Тестовая 1',
            ],
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(201);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals(300, (float) $order->subtotal);
        $this->assertEquals(300, (float) $order->total);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(1, $order->items()->count());

        $product->refresh();
        $this->assertEquals(3, (float) $product->stock_quantity, 'Остаток должен уменьшиться на количество из корзины');

        // Корзина должна быть очищена после создания заказа
        $this->assertEquals(0, $cart->items()->count());
    }

    public function test_creating_order_with_empty_cart_fails()
    {
        $user = User::factory()->create();
        Cart::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(400);
        $this->assertEquals(0, Order::count());
    }

    public function test_creating_order_requires_authentication()
    {
        $response = $this->postJson('/api/orders', [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(401);
    }
}
