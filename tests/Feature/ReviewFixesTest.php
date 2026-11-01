<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Исправления по итогам ревью: канал продажи, недоплата на кассе,
// поиск заказов, закрытые роуты.
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        RolePermission::firstOrCreate(['role' => 'cashier', 'permission' => 'pos.access']);

        return User::factory()->create(['role' => 'cashier']);
    }

    private function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'status'         => 'pending',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs));
    }

    private function posSale(User $cashier, array $extra = [])
    {
        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => 100, 'sale_price' => null, 'purchase_price' => 0]);

        return $this->actingAs($cashier)->postJson('/api/pos/sales', array_merge([
            'items'           => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100]],
            'cash_amount'     => 100,
            'transfer_amount' => 0,
        ], $extra));
    }

    public function test_pos_sale_is_marked_with_pos_channel()
    {
        $this->posSale($this->cashier())->assertStatus(201);

        $this->assertEquals(Order::CHANNEL_POS, Order::first()->channel);
    }

    public function test_underpaid_pos_sale_without_debt_is_rejected()
    {
        $response = $this->posSale($this->cashier(), ['cash_amount' => 40]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Недостаточно оплаты: не хватает 60 сом.']);
        $this->assertEquals(0, Order::count());
    }

    public function test_debt_sale_requires_customer()
    {
        $this->posSale($this->cashier(), ['cash_amount' => 0, 'is_debt' => true])->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    public function test_orders_source_filter_uses_channel_not_notes()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->order(['channel' => Order::CHANNEL_ONLINE, 'notes' => 'позвоните, код домофона POS']);
        $this->order(['channel' => Order::CHANNEL_POS, 'notes' => 'Продажа на кассе']);

        $pos = $this->actingAs($admin)->getJson('/api/orders?source=pos')->json('data');
        $online = $this->actingAs($admin)->getJson('/api/orders?source=online')->json('data');

        $this->assertCount(1, $pos);
        $this->assertEquals('Продажа на кассе', $pos[0]['notes']);
        $this->assertCount(1, $online);
    }

    public function test_orders_search_by_number_and_id()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order(['order_number' => 'P-777']);
        $this->order(['order_number' => 'P-111']);

        $byNumber = $this->actingAs($admin)->getJson('/api/orders?search=777')->assertOk()->json('data');
        $byId = $this->actingAs($admin)->getJson('/api/orders?search=' . $order->id)->assertOk()->json('data');

        $this->assertContains($order->id, array_column($byNumber, 'id'));
        $this->assertContains($order->id, array_column($byId, 'id'));
    }

    public function test_generate_sku_requires_staff()
    {
        $this->getJson('/api/products/generate-sku')->assertStatus(401);
    }

    public function test_review_approval_requires_permission()
    {
        $cashier  = $this->cashier();
        $reviewId = \Illuminate\Support\Facades\DB::table('reviews')->insertGetId([
            'user_id'    => User::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
            'rating'     => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Модерация — право products.edit (проверка внутри ReviewController::approve)
        $this->actingAs($cashier)->postJson("/api/reviews/{$reviewId}/approve")->assertStatus(403);

        RolePermission::create(['role' => 'cashier', 'permission' => 'products.edit']);
        $this->actingAs($cashier)->postJson("/api/reviews/{$reviewId}/approve")->assertSuccessful();
    }

    public function test_demo_payment_confirmation_is_not_available_in_production()
    {
        // Роуты /self-service/pay/* регистрируются только в local/testing —
        // проверяем условие регистрации, а не сам роут (тесты идут в testing)
        $this->assertTrue(app()->environment('testing'));
        $routes = collect(app('router')->getRoutes()->getRoutes())->map->uri();
        $this->assertContains('api/self-service/pay/{token}/confirm', $routes);

        $source = file_get_contents(base_path('routes/api.php'));
        $this->assertStringContainsString("if (app()->environment(['local', 'testing']))", $source);
    }
}
