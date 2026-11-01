<?php

namespace Tests\Feature;

use App\Contracts\PaymentProviderInterface;
use App\Models\CustomerDebt;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\GoPayClient;
use App\Services\Pos\PosSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

// Продажа в долг: создание на кассе, погашение, отмена, возврат и ручная
// смена статуса оплаты — деньги в учёте должны сходиться на каждом шаге.
class PosDebtSaleTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    private User $admin;
    private User $customer;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentProviderInterface::class, new FakePaymentProvider());
        config(['services.gopay.webhook_secret' => self::WEBHOOK_SECRET]);

        Setting::updateOrCreate(['key' => 'pos_allow_debt'], ['value' => '1']);
        $this->admin    = User::factory()->create(['role' => 'admin']);
        $this->customer = User::factory()->create(['role' => 'user']);
    }

    private function cashier(): User
    {
        RolePermission::firstOrCreate(['role' => 'cashier', 'permission' => 'pos.access']);

        return User::factory()->create(['role' => 'cashier']);
    }

    private function debtSale(float $cash, array $extra = [])
    {
        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => 1000, 'sale_price' => null, 'purchase_price' => 0]);

        return $this->actingAs($this->cashier())->postJson('/api/pos/sales', array_merge([
            'items'       => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]],
            'cash_amount' => $cash,
            'is_debt'     => true,
            'user_id'     => $this->customer->id,
        ], $extra));
    }

    private function pay(CustomerDebt $debt, float $amount)
    {
        return $this->actingAs($this->admin)->postJson("/api/accounting/debts/{$debt->id}/pay", ['amount' => $amount]);
    }

    private function sum(string $type, ?string $category = null): float
    {
        $query = FinancialTransaction::where('type', $type);
        if ($category) {
            $query->where('category', $category);
        }

        return round((float) $query->sum('amount'), 2);
    }

    public function test_debt_sale_with_cash_covering_total_is_rejected()
    {
        $response = $this->debtSale(1000)->assertStatus(422);
        $this->assertStringContainsString('покрывает весь чек', $response->json('message'));

        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, CustomerDebt::count());
    }

    public function test_debt_sale_is_rejected_when_setting_is_not_enabled()
    {
        Setting::where('key', 'pos_allow_debt')->delete();

        $this->debtSale(0)->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    public function test_debt_sale_with_down_payment_creates_debt_for_the_rest()
    {
        $this->debtSale(300)->assertStatus(201);

        $order = Order::first();
        $debt  = CustomerDebt::first();
        $this->assertEquals('pending', $order->payment_status);
        $this->assertEquals(300, (float) $debt->paid_amount);
        $this->assertEquals(700, (float) $debt->remaining_amount);
        $this->assertEquals(300, $this->sum('income'));
    }

    public function test_qr_down_payment_creates_debt_only_after_bank_confirmation()
    {
        $response = $this->debtSale(200, ['qr_amount' => 300, 'due_date' => now()->addWeek()->toDateString()]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('awaiting_payment'));
        // Пока банк не подтвердил — ни долга, ни денег
        $this->assertEquals(0, CustomerDebt::count());
        $this->assertEquals(0, FinancialTransaction::count());

        $order = Order::first();
        $this->commit($order->payments()->first()->transaction_id, '300.00')->assertOk();

        $order->refresh();
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals('pending', $order->payment_status);

        $debt = CustomerDebt::first();
        $this->assertEquals(500, (float) $debt->paid_amount);
        $this->assertEquals(500, (float) $debt->remaining_amount);
        $this->assertEquals('partial', $debt->status);
        $this->assertEquals(now()->addWeek()->toDateString(), $debt->due_date instanceof \DateTimeInterface ? $debt->due_date->format('Y-m-d') : substr((string) $debt->due_date, 0, 10));
        $this->assertEquals(200, (float) FinancialTransaction::where('category', PosSaleService::CATEGORY_CASH)->sum('amount'));
        $this->assertEquals(300, (float) FinancialTransaction::where('category', PosSaleService::CATEGORY_QR)->sum('amount'));

        $status = $this->actingAs(User::find($order->staff_id))->getJson("/api/pos/sales/{$order->id}/status")->assertOk();
        $this->assertFalse($status->json('awaiting_payment'));
        $this->assertEquals('delivered', $status->json('status'));
    }

    public function test_cancelled_qr_debt_sale_creates_no_debt()
    {
        $this->debtSale(0, ['qr_amount' => 300])->assertStatus(201);
        $order = Order::first();

        $this->actingAs(User::find($order->staff_id))->postJson("/api/pos/sales/{$order->id}/cancel")->assertOk();

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(0, CustomerDebt::count());
    }

    public function test_debt_sale_with_cash_and_qr_covering_total_is_rejected()
    {
        $this->debtSale(400, ['qr_amount' => 600])->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    public function test_debt_paid_by_qr_is_recorded_only_after_bank_confirmation()
    {
        $this->debtSale(300);
        $debt = CustomerDebt::first();

        $qr = $this->actingAs($this->admin)->postJson("/api/accounting/debts/{$debt->id}/qr", ['amount' => 200]);
        $qr->assertStatus(201);
        $this->assertNotEmpty($qr->json('qr_value'));
        $this->assertEquals('pending', $qr->json('status'));
        // QR погашения — не оплата заказа
        $this->assertEquals(0, Order::first()->payments()->count());
        $this->assertEquals(700, (float) $debt->fresh()->remaining_amount);

        $payment = \App\Models\Payment::find($qr->json('id'));
        $this->commit($payment->transaction_id, '200.00')->assertOk();

        $debt->refresh();
        $this->assertEquals(500, (float) $debt->paid_amount);
        $this->assertEquals(500, (float) $debt->remaining_amount);
        $this->assertEquals('qr', $debt->payments()->first()->payment_method);
        $this->assertEquals(200, $this->sum('income', 'debt_payment'));
        $this->assertEquals('pending', Order::first()->payment_status, 'Заказ не оплачен — остаток ещё в долге');
        // Повтор вебхука ничего не задваивает
        $this->commit($payment->transaction_id, '200.00')->assertOk();
        $this->assertEquals(1, $debt->payments()->count());

        $status = $this->actingAs($this->admin)->getJson("/api/accounting/debts/qr/{$payment->id}")->assertOk();
        $this->assertEquals('completed', $status->json('status'));
        $this->assertEquals(500, (float) $status->json('debt.remaining_amount'));

        // Банковскую выплату не удаляют
        $this->actingAs($this->admin)->deleteJson('/api/accounting/debts/payments/' . $debt->payments()->first()->id)->assertStatus(409);
    }

    public function test_debt_qr_over_remaining_is_rejected_and_new_qr_cancels_old_one()
    {
        $this->debtSale(300);
        $debt = CustomerDebt::first();

        $this->actingAs($this->admin)->postJson("/api/accounting/debts/{$debt->id}/qr", ['amount' => 800])->assertStatus(422);

        $first  = $this->actingAs($this->admin)->postJson("/api/accounting/debts/{$debt->id}/qr", ['amount' => 100])->assertStatus(201);
        $this->actingAs($this->admin)->postJson("/api/accounting/debts/{$debt->id}/qr", ['amount' => 150])->assertStatus(201);

        $this->assertEquals('failed', \App\Models\Payment::find($first->json('id'))->status);
    }

    public function test_qr_confirmed_after_debt_closed_is_flagged_for_refund()
    {
        $this->debtSale(300);
        $debt = CustomerDebt::first();

        $qr = $this->actingAs($this->admin)->postJson("/api/accounting/debts/{$debt->id}/qr", ['amount' => 700])->assertStatus(201);
        // Пока QR ждал — долг погасили наличными
        $this->pay($debt, 700)->assertOk();

        $payment = \App\Models\Payment::find($qr->json('id'));
        $this->commit($payment->transaction_id, '700.00')->assertOk();

        $this->assertEquals(1, $debt->payments()->count());
        $this->assertEquals(700, $this->sum('income', \App\Services\Payments\PaymentSettlementService::CATEGORY_NEEDS_REFUND));
    }

    public function test_debt_payment_cannot_exceed_remaining_or_hit_closed_debt()
    {
        $this->debtSale(300);
        $debt = CustomerDebt::first();

        $this->pay($debt, 800)->assertStatus(422);
        $this->pay($debt, 700)->assertOk();
        $this->pay($debt, 1)->assertStatus(409);

        $debt->refresh();
        $this->assertEquals('paid', $debt->status);
        $this->assertEquals(1000, (float) $debt->paid_amount);
        $this->assertEquals(0, (float) $debt->remaining_amount);
        $this->assertEquals('paid', Order::first()->payment_status);
        $this->assertEquals(1000, $this->sum('income'));
    }

    public function test_cancelling_debt_sale_refunds_down_payment_and_debt_payments()
    {
        $this->debtSale(300);
        $this->pay(CustomerDebt::first(), 200)->assertOk();
        $order = Order::first();

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['status' => 'cancelled'])->assertOk();

        $this->assertEquals(500, $this->sum('expense', 'refund'));
        $this->assertEquals('cancelled', CustomerDebt::first()->status);
        $this->assertEquals('refunded', $order->fresh()->payment_status);
    }

    public function test_return_after_debt_paid_off_refunds_everything_received()
    {
        $this->debtSale(300);
        $this->pay(CustomerDebt::first(), 700)->assertOk();
        $order = Order::first();
        $item  = $order->items()->first();

        $response = $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/return-items", [
            'items' => [['id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertOk();
        $this->assertEquals(1000, $response->json('refund_amount'));
        $this->assertEquals(0, $response->json('debt_reduced'));
    }

    public function test_partial_return_on_unpaid_debt_reduces_debt_first()
    {
        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => 500, 'sale_price' => null, 'purchase_price' => 0]);
        $this->actingAs($this->cashier())->postJson('/api/pos/sales', [
            'items'       => [['product_id' => $product->id, 'quantity' => 2, 'price' => 500]],
            'cash_amount' => 800,
            'is_debt'     => true,
            'user_id'     => $this->customer->id,
        ])->assertStatus(201);
        $order = Order::first();

        // Долг 200, возврат одной штуки на 500: 200 списываются с долга, 300 — наличными
        $response = $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/return-items", [
            'items' => [['id' => $order->items()->first()->id, 'quantity' => 1]],
        ]);

        $response->assertOk();
        $this->assertEquals(200, $response->json('debt_reduced'));
        $this->assertEquals(300, $response->json('refund_amount'));

        $debt = CustomerDebt::first();
        $this->assertEquals(500, (float) $debt->total_amount);
        $this->assertEquals(500, (float) $debt->paid_amount);
        $this->assertEquals(0, (float) $debt->remaining_amount);
    }

    public function test_manual_paid_mark_records_only_outstanding_and_unmark_keeps_real_money()
    {
        $this->debtSale(300);
        $order = Order::first();

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_status' => 'paid'])->assertOk();
        $this->assertEquals(1000, $this->sum('income'));
        $this->assertEquals('paid', CustomerDebt::first()->status);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", ['payment_status' => 'pending'])->assertOk();
        $this->assertEquals(300, $this->sum('income'), 'Первый взнос — реальные деньги, он остаётся');

        $debt = CustomerDebt::first();
        $this->assertEquals('partial', $debt->status);
        $this->assertEquals(300, (float) $debt->paid_amount);
        $this->assertEquals(700, (float) $debt->remaining_amount);
    }
}
