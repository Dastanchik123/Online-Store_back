<?php

namespace Tests\Feature;

use App\Contracts\PaymentProviderInterface;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Pos\PosSaleService;
use App\Services\Payments\GoPayClient;
use App\Services\WeightedBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

// Касса: смешанная оплата наличные + QR, права кассира, вес, номера P-…
class PosMixedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProvider $provider;
    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakePaymentProvider();
        $this->app->instance(PaymentProviderInterface::class, $this->provider);
        config(['services.gopay.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    private function cashier(array $extra = []): User
    {
        foreach (array_merge(['pos.access'], $extra) as $permission) {
            RolePermission::firstOrCreate(['role' => 'cashier', 'permission' => $permission]);
        }

        return User::factory()->create(['role' => 'cashier']);
    }

    private function product(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge(['stock_quantity' => 10, 'price' => 1000, 'sale_price' => null, 'purchase_price' => 0], $attrs));
    }

    private function sale(User $cashier, Product $product, array $extra = [])
    {
        return $this->actingAs($cashier)->postJson('/api/pos/sales', array_merge([
            'items'       => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]],
            'cash_amount' => 1000,
        ], $extra));
    }

    private function commit(string $providerPaymentId, string $amount)
    {
        $body  = json_encode(['event' => 'payment.committed', 'data' => ['payment_id' => $providerPaymentId, 'status' => 'COMMITTED', 'amount' => $amount]]);
        $nonce = 'n' . random_int(1000, 9999);

        return $this->call('POST', '/api/self-service/gopay/webhook', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_GOPAY_NONCE'     => $nonce,
            'HTTP_GOPAY_SIGNATURE' => GoPayClient::sign(self::WEBHOOK_SECRET, $nonce, $body),
        ], $body);
    }

    public function test_cash_sale_gets_sequential_p_numbers()
    {
        $cashier = $this->cashier();
        $product = $this->product();

        $this->assertEquals('P-1', $this->sale($cashier, $product)->assertStatus(201)->json('order_number'));
        $this->assertEquals('P-2', $this->sale($cashier, $product)->assertStatus(201)->json('order_number'));
    }

    public function test_mixed_payment_waits_for_qr_and_completes_after_bank_confirmation()
    {
        $cashier = $this->cashier();
        $product = $this->product();

        $response = $this->sale($cashier, $product, ['cash_amount' => 400, 'qr_amount' => 600]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('awaiting_payment'));
        $this->assertEquals(600, $response->json('qr_payment.amount'));
        $this->assertNotEmpty($response->json('qr_payment.qr_value'));

        $order = Order::first();
        $this->assertEquals('pending', $order->status);
        // Пока банк не подтвердил — ни списания, ни денег
        $this->assertEquals(10, (float) $product->fresh()->stock_quantity);
        $this->assertEquals(0, FinancialTransaction::count());

        $payment = $order->payments()->first();
        $this->commit($payment->transaction_id, '600.00')->assertOk();

        $order->refresh();
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('mixed', $order->payment_method);
        $this->assertEquals(9, (float) $product->fresh()->stock_quantity);
        $this->assertEquals(400, (float) FinancialTransaction::where('category', PosSaleService::CATEGORY_CASH)->sum('amount'));
        $this->assertEquals(600, (float) FinancialTransaction::where('category', PosSaleService::CATEGORY_QR)->sum('amount'));

        $status = $this->actingAs($cashier)->getJson("/api/pos/sales/{$order->id}/status")->assertOk();
        $this->assertFalse($status->json('awaiting_payment'));
    }

    public function test_qr_cannot_exceed_total()
    {
        $this->sale($this->cashier(), $this->product(), ['cash_amount' => 0, 'qr_amount' => 1500])->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    public function test_too_large_total_is_rejected_with_422_not_500()
    {
        $product = $this->product(['stock_quantity' => 2000000]);

        $this->sale($this->cashier(), $product, [
            'items'       => [['product_id' => $product->id, 'quantity' => 1100000, 'price' => 1000]],
            'cash_amount' => 1100000000,
        ])->assertStatus(422)->assertJsonPath('message', 'Сумма чека слишком большая — проверьте количество и цены товаров.');
        $this->assertEquals(0, Order::count());
    }

    public function test_cancelling_pending_qr_sale_cancels_qr_and_keeps_stock()
    {
        $cashier = $this->cashier();
        $product = $this->product();
        $orderId = $this->sale($cashier, $product, ['cash_amount' => 0, 'qr_amount' => 1000])->json('id');

        $this->actingAs($cashier)->postJson("/api/pos/sales/{$orderId}/cancel")->assertOk();

        $this->assertEquals('cancelled', Order::find($orderId)->status);
        $this->assertCount(1, $this->provider->cancelled);
        $this->assertEquals(10, (float) $product->fresh()->stock_quantity);
    }

    public function test_cancel_refused_when_qr_already_paid()
    {
        $cashier = $this->cashier();
        $orderId = $this->sale($cashier, $this->product(), ['cash_amount' => 0, 'qr_amount' => 1000])->json('id');
        $this->provider->cancelOutcome = PaymentProviderInterface::CANCEL_ALREADY_PAID;

        $this->actingAs($cashier)->postJson("/api/pos/sales/{$orderId}/cancel")->assertStatus(409);

        $this->assertEquals('paid', Order::find($orderId)->payment_status);
    }

    public function test_check_status_asks_provider_when_webhook_is_late()
    {
        $cashier = $this->cashier();
        $orderId = $this->sale($cashier, $this->product(), ['cash_amount' => 0, 'qr_amount' => 1000])->json('id');

        $this->actingAs($cashier)->getJson("/api/pos/sales/{$orderId}/status")->assertOk();
        $this->assertEquals('pending', Order::find($orderId)->status);

        $this->provider->status = PaymentProviderInterface::STATUS_PAID;
        $response = $this->actingAs($cashier)->getJson("/api/pos/sales/{$orderId}/status?check=1")->assertOk();

        $this->assertEquals('paid', $response->json('payment_status'));
    }

    public function test_new_qr_replaces_expired_one()
    {
        $cashier = $this->cashier();
        $orderId = $this->sale($cashier, $this->product(), ['cash_amount' => 0, 'qr_amount' => 1000])->json('id');

        $response = $this->actingAs($cashier)->postJson("/api/pos/sales/{$orderId}/qr")->assertOk();

        $this->assertCount(1, $this->provider->cancelled);
        $this->assertEquals(1000, $response->json('qr_payment.amount'));
        $this->assertEquals(1, Order::find($orderId)->payments()->where('status', 'pending')->count());
    }

    public function test_qr_provider_failure_cancels_sale()
    {
        $this->provider->failOnCreate = true;

        $this->sale($this->cashier(), $this->product(), ['cash_amount' => 0, 'qr_amount' => 1000])->assertStatus(503);

        $this->assertEquals('cancelled', Order::first()->status);
    }

    public function test_other_cashier_cannot_touch_sale()
    {
        $orderId = $this->sale($this->cashier(), $this->product(), ['cash_amount' => 0, 'qr_amount' => 1000])->json('id');
        $other   = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($other)->postJson("/api/pos/sales/{$orderId}/cancel")->assertStatus(403);
    }

    public function test_transfer_payment_is_no_longer_accepted()
    {
        $this->sale($this->cashier(), $this->product(), ['cash_amount' => 0, 'transfer_amount' => 1000])->assertStatus(422);
    }

    public function test_price_change_requires_permission()
    {
        $product = $this->product();

        $this->sale($this->cashier(), $product, ['items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 900]], 'cash_amount' => 900])
            ->assertStatus(422);

        $allowed = $this->cashier(['pos.change_price']);
        $this->sale($allowed, $product, ['items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 900]], 'cash_amount' => 900])
            ->assertStatus(201);
    }

    public function test_manual_weight_requires_permission()
    {
        $product = $this->product(['is_weighted' => true, 'unit' => 'кг', 'price' => 200]);
        $items   = [['product_id' => $product->id, 'quantity' => 1, 'price' => 200]];

        $this->sale($this->cashier(), $product, ['items' => $items, 'cash_amount' => 200])->assertStatus(422);
        $this->sale($this->cashier(['pos.manual_weight']), $product, ['items' => $items, 'cash_amount' => 200])->assertStatus(201);
    }

    public function test_weight_must_match_barcode()
    {
        $product = $this->product(['is_weighted' => true, 'unit' => 'кг', 'price' => 200]);
        $barcode = app(WeightedBarcodeService::class)->generate($product, 0.5);

        $this->sale($this->cashier(), $product, [
            'items'       => [['product_id' => $product->id, 'quantity' => 1.5, 'price' => 200, 'barcode' => $barcode]],
            'cash_amount' => 300,
        ])->assertStatus(422);

        $this->sale($this->cashier(), $product, [
            'items'       => [['product_id' => $product->id, 'quantity' => 0.5, 'price' => 200, 'barcode' => $barcode]],
            'cash_amount' => 100,
        ])->assertStatus(201);
    }

    public function test_piece_goods_cannot_be_sold_fractionally()
    {
        $product = $this->product(['unit' => 'шт']);

        $this->sale($this->cashier(), $product, ['items' => [['product_id' => $product->id, 'quantity' => 0.5, 'price' => 1000]], 'cash_amount' => 500])
            ->assertStatus(422);
    }
}
