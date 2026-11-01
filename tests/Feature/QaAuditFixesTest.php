<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Регрессии по итогам QA-аудита фронт ↔ API
class QaAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        RolePermission::create(['role' => 'cashier', 'permission' => 'pos.access']);

        return User::factory()->create(['role' => 'cashier']);
    }

    private function sell(User $cashier): Order
    {
        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => 100, 'sale_price' => null, 'purchase_price' => 50]);

        $id = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items'       => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100]],
            'cash_amount' => 100,
        ])->assertCreated()->json('id') ?? Order::latest('id')->value('id');

        return Order::findOrFail($id);
    }

    public function test_cashier_without_orders_view_sees_own_pos_sales_in_history()
    {
        $cashier = $this->cashier();
        $other   = User::factory()->create(['role' => 'cashier']);

        $mine    = $this->sell($cashier);
        $foreign = $this->sell($other);

        $ids = collect($this->actingAs($cashier)->getJson('/api/orders?source=pos')
            ->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($foreign->id));
    }

    public function test_pos_sale_rejects_underpayment_by_one_tyiyn()
    {
        $cashier = $this->cashier();
        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => 250, 'sale_price' => null, 'purchase_price' => 100]);

        $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items'       => [['product_id' => $product->id, 'quantity' => 1, 'price' => 250]],
            'cash_amount' => 249.99,
        ])->assertStatus(422);

        $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items'       => [['product_id' => $product->id, 'quantity' => 1, 'price' => 250]],
            'cash_amount' => 250,
        ])->assertCreated();
    }

    public function test_customer_without_pos_access_still_sees_only_own_orders()
    {
        $customer = User::factory()->create(['role' => 'user']);
        $this->sell($this->cashier());

        $this->actingAs($customer)->getJson('/api/orders?source=pos')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cashier_can_search_customers_for_debt_sale()
    {
        $cashier = $this->cashier();
        User::factory()->create(['role' => 'user', 'name' => 'Иван Петров']);
        User::factory()->create(['role' => 'manager', 'name' => 'Иван Менеджер']);

        $res = $this->actingAs($cashier)->getJson('/api/pos/customers?search=Иван')->assertOk();

        $this->assertSame(['Иван Петров'], collect($res->json('data'))->pluck('name')->all());
        $this->assertArrayNotHasKey('role', $res->json('data.0'));
    }

    public function test_customer_search_requires_pos_permission()
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->getJson('/api/pos/customers?search=ab')->assertForbidden();
    }

    public function test_admin_can_clear_all_permissions_of_role()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'Кассир', 'is_system' => true]);
        RolePermission::create(['role' => 'cashier', 'permission' => 'pos.access']);

        $this->actingAs($admin)->postJson('/api/permissions/role/cashier', ['permissions' => []])
            ->assertOk();

        $this->assertDatabaseMissing('role_permissions', ['role' => 'cashier']);
    }

    public function test_self_service_device_terminal_id_must_be_unique()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/self-service-devices', ['terminal_id' => 'SS9'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/self-service-devices', ['terminal_id' => 'SS9'])
            ->assertStatus(422)->assertJsonValidationErrors('terminal_id');
    }

    public function test_product_gallery_rejects_non_image_files()
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create();

        // Настоящий файл: fake() определяет MIME по расширению, а не по содержимому
        $path = tempnam(sys_get_temp_dir(), 'qa');
        file_put_contents($path, '<?php echo 1;');
        $file = new \Illuminate\Http\UploadedFile($path, 'x.png', null, null, true);

        $this->actingAs($admin)->post("/api/products/{$product->id}", [
            '_method'         => 'PUT',
            'gallery_order'   => ['new:0'],
            'gallery_files'   => [$file],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('gallery_files.0');
    }

    public function test_product_gallery_keeps_only_own_existing_images()
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['images' => ['products/gallery/own.webp']]);

        $this->actingAs($admin)->putJson("/api/products/{$product->id}", [
            'gallery_order' => ['existing:products/gallery/own.webp', 'existing:settings/logo.png'],
        ])->assertOk();

        $this->assertSame(['products/gallery/own.webp'], $product->fresh()->images);
    }

    public function test_product_sale_price_must_be_below_price()
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $category = \App\Models\Category::factory()->create();

        $this->actingAs($admin)->postJson('/api/products', [
            'name' => 'Товар', 'sku' => 'QA-SALE', 'price' => 100, 'sale_price' => 500, 'category_id' => $category->id,
        ])->assertStatus(422)->assertJsonValidationErrors('sale_price');

        $product = Product::factory()->create(['price' => 100, 'sale_price' => null]);
        $this->actingAs($admin)->putJson("/api/products/{$product->id}", ['sale_price' => 150])
            ->assertStatus(422)->assertJsonValidationErrors('sale_price');
        $this->actingAs($admin)->putJson("/api/products/{$product->id}", ['sale_price' => 80])->assertOk();
    }

    public function test_category_parent_cannot_create_cycle()
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $parent = \App\Models\Category::factory()->create(['parent_id' => null]);
        $child  = \App\Models\Category::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($admin)->putJson("/api/categories/{$parent->id}", ['parent_id' => $parent->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->actingAs($admin)->putJson("/api/categories/{$parent->id}", ['parent_id' => $child->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $other = \App\Models\Category::factory()->create(['parent_id' => null]);
        $this->actingAs($admin)->putJson("/api/categories/{$child->id}", ['parent_id' => $other->id])->assertOk();
    }

    public function test_banner_link_rejects_javascript_scheme()
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $image = \Illuminate\Http\UploadedFile::fake()->image('b.png');

        $this->actingAs($admin)->post('/api/banners-admin', [
            'image' => $image, 'link_url' => 'javascript:alert(1)',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('link_url');

        foreach (['/catalog?x=1', 'https://example.com'] as $url) {
            $this->actingAs($admin)->post('/api/banners-admin', [
                'image' => \Illuminate\Http\UploadedFile::fake()->image('b.png'), 'link_url' => $url,
            ], ['Accept' => 'application/json'])->assertCreated();
        }
    }

    private function purchase(User $admin, float $paid = 0): array
    {
        $supplier = \App\Models\Supplier::create(['name' => 'QA Поставщик']);
        $product  = Product::factory()->create(['stock_quantity' => 0, 'price' => 100]);

        $purchase = $this->actingAs($admin)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'paid_amount' => $paid,
            'items'       => [['product_id' => $product->id, 'quantity' => 10, 'buy_price' => 50]],
        ])->assertCreated()->json();

        return [$supplier, $product, $purchase];
    }

    public function test_purchase_payment_cannot_exceed_total()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$supplier, $product] = $this->purchase($admin);

        $this->actingAs($admin)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'paid_amount' => 1000,
            'items'       => [['product_id' => $product->id, 'quantity' => 1, 'buy_price' => 50]],
        ])->assertStatus(422)->assertJsonValidationErrors('paid_amount');

        $purchaseId = \App\Models\Purchase::latest('id')->value('id');
        $this->actingAs($admin)->postJson("/api/purchases/{$purchaseId}/pay", ['amount' => 600])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->actingAs($admin)->postJson("/api/purchases/{$purchaseId}/pay", ['amount' => 500])->assertOk();
        $this->assertEquals(0, (float) $supplier->fresh()->debt_to_supplier);
    }

    public function test_purchase_with_active_supplier_return_cannot_be_deleted_or_reitemized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$supplier, $product, $purchase] = $this->purchase($admin, 500);
        $item = \App\Models\PurchaseItem::where('purchase_id', $purchase['id'])->firstOrFail();

        $return = \App\Models\SupplierReturn::create([
            'supplier_id' => $supplier->id, 'user_id' => $admin->id,
            'status' => 'confirmed', 'return_date' => now(), 'total_amount' => 150, 'reason' => 'брак',
        ]);
        \App\Models\SupplierReturnItem::create([
            'supplier_return_id' => $return->id, 'purchase_item_id' => $item->id, 'product_id' => $product->id,
            'quantity' => 3, 'unit_cost' => 50, 'total' => 150,
        ]);

        $this->actingAs($admin)->deleteJson("/api/purchases/{$purchase['id']}")->assertStatus(409);
        $this->actingAs($admin)->putJson("/api/purchases/{$purchase['id']}", [
            'supplier_id' => $supplier->id, 'paid_amount' => 500,
            'items'       => [['product_id' => $product->id, 'quantity' => 1, 'buy_price' => 50]],
        ])->assertStatus(409);

        $this->assertEquals(10, (float) $product->fresh()->stock_quantity);
        $this->assertNotNull($item->fresh());
    }

    public function test_self_service_rejects_fractional_quantity_of_piece_goods()
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $device = $this->actingAs($admin)->postJson('/api/self-service-devices', ['terminal_id' => 'SSQ'])->json();
        $code   = $this->actingAs($admin)->postJson("/api/self-service-devices/{$device['id']}/pairing-code")->json('pairing_code');
        $token  = $this->postJson('/api/self-service/pair', ['pairing_code' => $code])->json('device_token');

        $product = Product::factory()->create(['stock_quantity' => 10, 'unit' => 'шт', 'is_weighted' => false, 'is_active' => true]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/self-service/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1.5]]])
            ->assertStatus(422);
    }

    public function test_gopay_order_id_survives_database_reset()
    {
        config(['services.gopay.api_key' => 'k', 'services.gopay.secret_key' => 's', 'app.url' => 'https://shop.test']);
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['status' => 'OK', 'code' => '0000', 'data' => [
                'payment_id' => str_repeat('a', 32), 'qr_url' => 'https://q', 'qr_data' => 'x', 'checkout_url' => 'https://c', 'expires_at' => '2026-10-22 23:00:00',
            ]]),
        ]);

        $order   = \App\Models\Order::create([
            'order_number' => 'S-1', 'channel' => 'self_service', 'status' => 'pending', 'payment_status' => 'pending',
            'subtotal' => 100, 'total' => 100, 'currency' => 'KGS', 'payment_method' => 'qr',
        ]);
        $payment = \App\Models\Payment::create(['order_id' => $order->id, 'payment_method' => 'qr', 'status' => 'pending', 'amount' => 100]);

        app(\App\Services\Payments\GoPayPaymentProvider::class)->createPayment($order, $payment);

        // После пересоздания БД id платежа повторяется — order_id должен отличаться
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($payment) {
            // тело уходит подписанной строкой (withBody), поэтому разбираем его сами
            $orderId = json_decode($request->body(), true)['order_id'] ?? '';

            return $orderId === 'pay-' . $payment->id . '-' . $payment->created_at->getTimestamp()
                && strlen($orderId) <= 32;
        });
    }

    public function test_public_receipt_by_uuid_hides_personal_data()
    {
        $cashier  = $this->cashier();
        $customer = User::factory()->create(['role' => 'user', 'name' => 'Иван Секретов', 'phone' => '+996555000111']);
        $product  = Product::factory()->create(['stock_quantity' => 10, 'price' => 100, 'sale_price' => null, 'purchase_price' => 40]);

        $orderId = $this->actingAs($cashier)->postJson('/api/pos/sales', [
            'items'       => [['product_id' => $product->id, 'quantity' => 2, 'price' => 100]],
            'cash_amount' => 200,
            'user_id'     => $customer->id,
        ])->assertCreated()->json('id');
        $order = Order::findOrFail($orderId);

        // Гость, без токена
        $this->app['auth']->forgetGuards();
        $res = $this->getJson("/api/receipts/{$order->uuid}")->assertOk()
            ->assertJsonPath('order_number', $order->order_number)
            ->assertJsonPath('total', 200)
            ->assertJsonPath('items.0.quantity', 2);

        $body = $res->getContent();
        foreach (['Иван Секретов', '+996555000111', $cashier->name, 'purchase_price', '"user"', '"staff"', 'shipping'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // По номеру/числовому id и чужому uuid — 404
        $this->getJson("/api/receipts/{$order->id}")->assertNotFound();
        $this->getJson("/api/receipts/{$order->order_number}")->assertNotFound();
        $this->getJson('/api/receipts/' . \Illuminate\Support\Str::uuid())->assertNotFound();
    }

    public function test_receipt_public_url_setting_is_validated_and_exposed()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/settings', ['settings' => ['receipt_public_url' => 'javascript:alert(1)']])
            ->assertStatus(422)->assertJsonValidationErrors('receipt_public_url');

        $this->actingAs($admin)->postJson('/api/settings', ['settings' => ['receipt_public_url' => ' https://shop.kg/ ']])
            ->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'receipt_public_url', 'value' => 'https://shop.kg']);

        $this->getJson('/api/settings/public')->assertOk()->assertJsonPath('receipt_public_url', 'https://shop.kg');
    }

    public function test_thermal_receipt_html_has_qr_placeholder()
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        \App\Models\Setting::updateOrCreate(['key' => 'receipt_public_url'], ['value' => 'https://shop.kg']);
        $order = $this->sell($this->cashier());

        $this->actingAs($admin)->get("/api/reports/order/{$order->id}/thermal/html")
            ->assertOk()
            ->assertSee('data-url="https://shop.kg/receipt/' . $order->uuid . '"', false);
    }

    public function test_permissions_for_unknown_role_are_rejected()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/permissions/role/no_such_role', ['permissions' => ['pos.access']])
            ->assertNotFound();

        $this->assertDatabaseMissing('role_permissions', ['role' => 'no_such_role']);
    }
}
