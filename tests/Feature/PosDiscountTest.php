<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CustomerDebt;
use App\Models\Order;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(array $permissions = ['pos.access']): User
    {
        // Миграция выдаёт кассиру pos.discount по умолчанию — здесь права
        // каждого теста задаются явно
        RolePermission::where('role', 'cashier')->delete();
        foreach ($permissions as $permission) {
            RolePermission::create(['role' => 'cashier', 'permission' => $permission]);
        }

        return User::factory()->create(['role' => 'cashier']);
    }

    private function sale(User $cashier, Product $product, array $extra = [])
    {
        return $this->actingAs($cashier)->postJson('/api/pos/sales', array_merge([
            'items'           => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]],
            'cash_amount'     => 1000,
            'transfer_amount' => 0,
        ], $extra));
    }

    private function product(): Product
    {
        return Product::factory()->create(['stock_quantity' => 10, 'price' => 1000, 'sale_price' => null, 'purchase_price' => 0]);
    }

    public function test_discount_field_from_client_is_ignored()
    {
        $response = $this->sale($this->cashier(), $this->product(), ['discount' => 900]);

        $response->assertStatus(201);
        $this->assertEquals(1000, (float) Order::first()->total);
        $this->assertEquals(0, (float) Order::first()->discount);
    }

    public function test_manual_discount_without_permission_is_rejected()
    {
        $response = $this->sale($this->cashier(), $this->product(), ['manual_discount' => 50]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'У вас нет права давать скидку.']);
        $this->assertEquals(0, Order::count());
    }

    public function test_manual_discount_within_limit_is_applied()
    {
        Setting::updateOrCreate(['key' => 'pos_max_discount_percent'], ['value' => '10']);

        $response = $this->sale($this->cashier(['pos.access', 'pos.discount']), $this->product(), ['manual_discount' => 100]);

        $response->assertStatus(201);
        $order = Order::first();
        $this->assertEquals(900, (float) $order->total);
        $this->assertEquals(100, (float) $order->discount);
        $this->assertEquals(100, (float) $order->manual_discount);
    }

    public function test_manual_discount_over_limit_is_rejected()
    {
        Setting::updateOrCreate(['key' => 'pos_max_discount_percent'], ['value' => '10']);

        $response = $this->sale($this->cashier(['pos.access', 'pos.discount']), $this->product(), ['manual_discount' => 150]);

        $response->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    public function test_unlimited_discount_permission_ignores_limit_but_not_subtotal()
    {
        Setting::updateOrCreate(['key' => 'pos_max_discount_percent'], ['value' => '10']);
        $cashier = $this->cashier(['pos.access', 'pos.discount_unlimited']);
        $product = $this->product();

        $this->sale($cashier, $product, ['manual_discount' => 600])->assertStatus(201);
        $this->assertEquals(400, (float) Order::first()->total);

        $this->sale($cashier, $product, ['manual_discount' => 1500])->assertStatus(422);
        $this->assertEquals(1, Order::count());
    }

    public function test_coupon_discount_is_computed_on_server_and_counted()
    {
        $coupon = Coupon::create(['code' => 'SALE20', 'type' => 'percent', 'value' => 20, 'min_order_amount' => 0, 'is_active' => true]);

        $response = $this->sale($this->cashier(), $this->product(), ['coupon_code' => 'SALE20']);

        $response->assertStatus(201);
        $order = Order::first();
        $this->assertEquals(800, (float) $order->total);
        $this->assertEquals(0, (float) $order->manual_discount);
        $this->assertEquals($coupon->id, $order->coupon_id);
        $this->assertEquals(1, $coupon->fresh()->used_count);
    }

    public function test_invalid_coupon_is_rejected()
    {
        $response = $this->sale($this->cashier(), $this->product(), ['coupon_code' => 'NOPE']);

        $response->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    public function test_debt_is_calculated_from_total_after_discount()
    {
        $customer = User::factory()->create(['role' => 'user']);
        Setting::updateOrCreate(['key' => 'pos_max_discount_percent'], ['value' => '10']);
        Setting::updateOrCreate(['key' => 'pos_allow_debt'], ['value' => '1']);

        $response = $this->sale($this->cashier(['pos.access', 'pos.discount']), $this->product(), [
            'manual_discount' => 100,
            'cash_amount'     => 0,
            'is_debt'         => true,
            'user_id'         => $customer->id,
        ]);

        $response->assertStatus(201);
        $debt = CustomerDebt::first();
        $this->assertEquals(900, (float) $debt->total_amount);
        $this->assertEquals(900, (float) $debt->remaining_amount);
    }
}
