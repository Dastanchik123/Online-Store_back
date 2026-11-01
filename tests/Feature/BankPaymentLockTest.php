<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Оплату подтвердил банк (QR GoPay) — статус и способ оплаты вручную не меняются
class BankPaymentLockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function order(array $attrs = []): Order
    {
        $order = Order::create(array_merge([
            'channel'        => Order::CHANNEL_ONLINE,
            'user_id'        => User::factory()->create(['role' => 'user'])->id,
            'status'         => 'processing',
            'payment_status' => 'paid',
            'payment_method' => 'mbank',
            'subtotal'       => 500,
            'total'          => 500,
        ], $attrs));

        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => 500]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku,
            'quantity'   => 1, 'price' => 500, 'total' => 500,
        ]);

        return $order;
    }

    private function bankPayment(Order $order, string $method = 'mbank', string $status = 'completed'): Payment
    {
        return Payment::create([
            'order_id'       => $order->id,
            'payment_method' => $method,
            'status'         => $status,
            'amount'         => $order->total,
            'transaction_id' => 'gp-' . $order->id . '-' . $method,
            'paid_at'        => $status === 'completed' ? now() : null,
        ]);
    }

    public function test_bank_paid_order_payment_status_cannot_be_changed()
    {
        $order = $this->order();
        $this->bankPayment($order);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Заказ оплачен через банк (QR GoPay) — статус и способ оплаты изменить нельзя. Для возврата денег отмените заказ или оформите возврат.']);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_method' => 'cash'])->assertStatus(422);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('mbank', $order->fresh()->payment_method);
    }

    public function test_bank_paid_order_with_status_removed_earlier_can_only_be_restored_to_paid()
    {
        // Статус сняли вручную до блокировки — заказ не должен застрять
        $order = $this->order(['payment_status' => 'pending']);
        $this->bankPayment($order);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_status' => 'failed'])->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_status' => 'paid', 'payment_method' => 'cash'])->assertStatus(422);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_status' => 'paid', 'payment_method' => 'mbank'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_status' => 'pending'])->assertStatus(422);
    }

    public function test_bank_paid_order_status_can_still_change_with_same_payment_fields()
    {
        $order = $this->order();
        $this->bankPayment($order);

        // Форма админки всегда шлёт и оплату — без изменений это не блокируется
        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'status'         => 'shipped',
            'payment_status' => 'paid',
            'payment_method' => 'mbank',
        ])->assertOk();

        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_bank_paid_order_can_be_cancelled_with_cash_refund()
    {
        $order = $this->order();
        $this->bankPayment($order);
        \App\Models\FinancialTransaction::create([
            'type' => 'income', 'amount' => 500, 'category' => 'sale',
            'trackable_type' => Order::class, 'trackable_id' => $order->id,
        ]);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'status'         => 'cancelled',
            'payment_status' => 'paid',
            'payment_method' => 'mbank',
        ])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEquals(500, (float) \App\Models\FinancialTransaction::where('category', 'refund')->sum('amount'));
    }

    public function test_manually_paid_order_stays_editable()
    {
        $order = $this->order(['payment_method' => 'cash']);
        // Сотрудник отметил оплату наличными — это не банк
        Payment::create(['order_id' => $order->id, 'payment_method' => 'cash', 'status' => 'completed', 'amount' => 500, 'paid_at' => now()]);
        // Неоплаченный QR по заказу тоже не блокирует
        $this->bankPayment($order, 'mbank', 'pending');

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_method' => 'transfer'])->assertOk();
        $this->assertSame('transfer', $order->fresh()->payment_method);
    }

    public function test_qr_method_cannot_be_set_manually()
    {
        $order = $this->order(['payment_status' => 'pending', 'payment_method' => 'cash']);

        foreach (['mbank', 'qr', 'mixed'] as $method) {
            $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_method' => $method])->assertStatus(422);
        }
        $this->assertSame('cash', $order->fresh()->payment_method);

        // Заказ с сайта, ожидающий QR, сохраняется со своим mbank как есть
        $pendingQr = $this->order(['payment_status' => 'pending', 'payment_method' => 'mbank', 'status' => 'pending']);
        $this->actingAs($this->admin)->putJson("/api/orders/{$pendingQr->id}", [
            'status' => 'processing', 'payment_status' => 'pending', 'payment_method' => 'mbank',
        ])->assertOk();
    }

    public function test_bank_payment_cannot_be_faked_or_reverted_through_payments_api()
    {
        $order = $this->order(['payment_status' => 'pending', 'status' => 'pending']);
        $pending = $this->bankPayment($order, 'mbank', 'pending');

        $this->actingAs($this->admin)->postJson('/api/payments', ['order_id' => $order->id, 'payment_method' => 'qr', 'amount' => 500])
            ->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/api/payments/{$pending->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_orders_list_and_details_report_bank_payment()
    {
        $paid = $this->order();
        $this->bankPayment($paid);
        $manual = $this->order(['payment_method' => 'cash']);

        $list = collect($this->actingAs($this->admin)->getJson('/api/orders')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue((bool) $list[$paid->id]['paid_by_bank']);
        $this->assertFalse((bool) $list[$manual->id]['paid_by_bank']);

        $this->actingAs($this->admin)->getJson("/api/orders/{$paid->id}")->assertOk()->assertJsonPath('paid_by_bank', true);
    }
}
