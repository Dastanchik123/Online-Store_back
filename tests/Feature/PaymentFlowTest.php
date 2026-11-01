<?php

namespace Tests\Feature;

use App\Contracts\PaymentProviderInterface;
use App\Models\CustomerDebt;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\Payments\GoPayClient;
use App\Services\SelfServiceOrderService;
use App\Support\Alerts;
use App\Exceptions\SelfServiceException;
use App\Events\NewOrderPlaced;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

// Денежный контур QR-оплаты: вебхук, двойная оплата, оплата после отмены,
// отмена платежа у провайдера, истечение заказов, киоск.
class PaymentFlowTest extends TestCase
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

    // ---------------------------------------------------------------
    // Помощники
    // ---------------------------------------------------------------

    private function product(float $stock = 10, float $price = 100): Product
    {
        return Product::factory()->create(['stock_quantity' => $stock, 'price' => $price, 'sale_price' => null, 'purchase_price' => 0]);
    }

    private function onlineOrder(array $attrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id'        => User::factory()->create()->id,
            'status'         => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'mbank',
            'channel'        => Order::CHANNEL_ONLINE,
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs));

        $product = $this->product(9);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'quantity' => 1, 'price' => 100, 'total' => 100,
        ]);

        return $order;
    }

    private function payment(Order $order, string $status = 'pending', float $amount = 100): Payment
    {
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'mbank', 'status' => 'pending',
            'amount' => $amount, 'currency' => 'SOM',
        ]);
        $payment->update(['transaction_id' => 'gp-' . $payment->id, 'status' => $status]);

        return $payment;
    }

    private function webhook(array $payload)
    {
        $body  = json_encode($payload);
        $nonce = bin2hex(random_bytes(8));

        return $this->call('POST', '/api/self-service/gopay/webhook', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_GOPAY_NONCE'     => $nonce,
            'HTTP_GOPAY_SIGNATURE' => GoPayClient::sign(self::WEBHOOK_SECRET, $nonce, $body),
        ], $body);
    }

    private function committedEvent(Payment $payment, ?string $amount = null): array
    {
        return ['event' => 'payment.committed', 'data' => [
            'payment_id' => $payment->transaction_id,
            'status'     => 'COMMITTED',
            'amount'     => $amount ?? number_format((float) $payment->amount, 2, '.', ''),
        ], 'created_at' => now()->toIso8601String()];
    }

    private function staff(array $permissions): User
    {
        foreach ($permissions as $permission) {
            RolePermission::firstOrCreate(['role' => 'manager', 'permission' => $permission]);
        }

        return User::factory()->create(['role' => 'manager']);
    }

    // ---------------------------------------------------------------
    // Вебхук
    // ---------------------------------------------------------------

    public function test_committed_event_marks_online_order_paid_and_records_income()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);

        $this->webhook($this->committedEvent($payment))->assertOk();

        $order->refresh();
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('processing', $order->status);
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertEquals(1, FinancialTransaction::where('trackable_type', Order::class)->where('trackable_id', $order->id)->where('type', 'income')->count());

        // Повторная доставка того же события ничего не задваивает
        $this->webhook($this->committedEvent($payment))->assertOk();
        $this->assertEquals(1, FinancialTransaction::where('type', 'income')->count());
    }

    public function test_legacy_callback_format_is_still_accepted()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);

        $this->webhook(['payment_id' => $payment->transaction_id, 'status' => 'COMMITTED', 'amount' => '100.00'])->assertOk();

        $this->assertEquals('paid', $order->fresh()->payment_status);
    }

    public function test_invalid_signature_is_rejected()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);
        $body    = json_encode($this->committedEvent($payment));

        $this->call('POST', '/api/self-service/gopay/webhook', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_GOPAY_NONCE'     => 'abc',
            'HTTP_GOPAY_SIGNATURE' => 'FORGED',
        ], $body)->assertStatus(400);

        $this->assertEquals('pending', $order->fresh()->payment_status);
    }

    public function test_second_payment_for_paid_order_is_recorded_and_flagged_for_cash_refund()
    {
        $order = $this->onlineOrder();
        $first = $this->payment($order);
        $second = $this->payment($order);

        $this->webhook($this->committedEvent($first))->assertOk();
        $this->webhook($this->committedEvent($second))->assertOk();

        $this->assertEquals('completed', $second->fresh()->status);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // Приход по лишнему платежу привязан к платежу, а не к заказу
        $this->assertDatabaseHas('financial_transactions', [
            'trackable_type' => Payment::class,
            'trackable_id'   => $second->id,
            'type'           => 'income',
        ]);
        $alert = SystemAlert::where('type', Alerts::PAYMENT_NEEDS_REFUND)->first();
        $this->assertNotNull($alert);
        $this->assertEquals($second->id, $alert->data['payment_id']);
    }

    public function test_payment_for_cancelled_order_is_flagged_for_refund()
    {
        $order   = $this->onlineOrder(['status' => 'cancelled', 'payment_status' => 'failed']);
        $payment = $this->payment($order);

        $this->webhook($this->committedEvent($payment))->assertOk();

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertEquals(1, SystemAlert::where('type', Alerts::PAYMENT_NEEDS_REFUND)->count());
    }

    public function test_amount_mismatch_is_not_settled()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);

        $this->webhook($this->committedEvent($payment, '1.00'))->assertOk();

        $this->assertEquals('pending', $order->fresh()->payment_status);
        $this->assertEquals(1, SystemAlert::where('type', Alerts::PAYMENT_AMOUNT_MISMATCH)->count());
    }

    public function test_expired_event_closes_payment()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);

        $this->webhook(['event' => 'payment.expired', 'data' => ['payment_id' => $payment->transaction_id, 'status' => 'EXPIRED']])->assertOk();

        $this->assertEquals('failed', $payment->fresh()->status);
        $this->assertEquals('pending', $order->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Отмена и перевыпуск QR
    // ---------------------------------------------------------------

    public function test_cancelling_order_cancels_qr_at_provider_and_returns_stock()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);
        $product = $order->items()->first()->product;

        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->assertContains($payment->id, $this->provider->cancelled);
        $this->assertEquals('failed', $payment->fresh()->status);
        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, (float) $product->fresh()->stock_quantity);
    }

    public function test_cancel_is_refused_when_qr_was_just_paid()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);
        $this->provider->cancelOutcome = PaymentProviderInterface::CANCEL_ALREADY_PAID;

        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/cancel")->assertStatus(409);

        $this->assertEquals('paid', $order->fresh()->payment_status);
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertNotEquals('cancelled', $order->fresh()->status);
    }

    public function test_cancel_fails_safely_when_provider_unavailable()
    {
        $order = $this->onlineOrder();
        $this->payment($order);
        $this->provider->unavailableOnCancel = true;

        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/cancel")->assertStatus(503);

        $this->assertEquals('pending', $order->fresh()->status);
    }

    public function test_new_qr_cancels_previous_one()
    {
        $order = $this->onlineOrder();
        $old   = $this->payment($order);

        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/payment-qr")->assertOk();

        $this->assertContains($old->id, $this->provider->cancelled);
        $this->assertEquals('failed', $old->fresh()->status);
        $this->assertEquals(1, $order->payments()->where('status', 'pending')->count());
    }

    // ---------------------------------------------------------------
    // Отмена через смену статуса = та же логика, что кнопка «Отменить»
    // ---------------------------------------------------------------

    public function test_status_change_to_cancelled_refunds_paid_order_in_cash()
    {
        $order   = $this->onlineOrder();
        $payment = $this->payment($order);
        $this->webhook($this->committedEvent($payment))->assertOk();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson("/api/orders/{$order->id}", ['status' => 'cancelled'])->assertOk();

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals('refunded', $order->payment_status);
        $this->assertDatabaseHas('financial_transactions', [
            'trackable_type' => Order::class,
            'trackable_id'   => $order->id,
            'category'       => 'refund',
            'payment_method' => 'cash',
            'amount'         => 100,
        ]);
    }

    // ---------------------------------------------------------------
    // Возврат товаров с учётом скидки
    // ---------------------------------------------------------------

    public function test_partial_return_refunds_price_after_discount()
    {
        $order = $this->onlineOrder(['subtotal' => 1000, 'discount' => 200, 'total' => 800, 'payment_status' => 'paid', 'status' => 'delivered']);
        $item  = $order->items()->first();
        $item->update(['quantity' => 2, 'price' => 500, 'total' => 1000]);
        FinancialTransaction::create(['type' => 'income', 'amount' => 800, 'category' => 'sale', 'trackable_type' => Order::class, 'trackable_id' => $order->id]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->postJson("/api/orders/{$order->id}/return-items", [
            'items' => [['id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertOk();
        // 500 * (800 / 1000) = 400, а не 500
        $this->assertEquals(400, $response->json('refund_amount'));
    }

    public function test_return_on_debt_order_reduces_debt_instead_of_paying_cash()
    {
        $order = $this->onlineOrder(['payment_status' => 'pending', 'status' => 'delivered']);
        $item  = $order->items()->first();
        CustomerDebt::create(['user_id' => $order->user_id, 'order_id' => $order->id, 'total_amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100, 'status' => 'active']);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->postJson("/api/orders/{$order->id}/return-items", [
            'items' => [['id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertOk();
        $this->assertEquals(0, $response->json('refund_amount'));
        $this->assertEquals(100, $response->json('debt_reduced'));
        $this->assertEquals(0, FinancialTransaction::where('category', 'refund')->count());
    }

    // ---------------------------------------------------------------
    // Автоотмена брошенных заказов
    // ---------------------------------------------------------------

    public function test_expire_command_cancels_stale_qr_orders_only()
    {
        $stale = $this->onlineOrder();
        $stale->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->payment($stale);
        $staleProduct = $stale->items()->first()->product;

        $fresh = $this->onlineOrder();
        $cod   = $this->onlineOrder(['payment_method' => 'cash']);
        $cod->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->artisan('orders:expire-unpaid')->assertExitCode(0);

        $this->assertEquals('cancelled', $stale->fresh()->status);
        $this->assertEquals(10, (float) $staleProduct->fresh()->stock_quantity);
        $this->assertEquals('pending', $fresh->fresh()->status);
        $this->assertEquals('pending', $cod->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Касса самообслуживания
    // ---------------------------------------------------------------

    public function test_kiosk_payment_is_completed_even_if_stock_ran_out_meanwhile()
    {
        $product = $this->product(1);
        $order   = app(SelfServiceOrderService::class)->createOrder([['product_id' => $product->id, 'quantity' => 1]], 'SS1');
        $this->assertStringStartsWith('S-', $order->order_number);

        // Пока покупатель платил, последний товар продали на кассе
        $product->update(['stock_quantity' => 0]);

        $payment = $order->payments()->first();
        $this->webhook($this->committedEvent($payment))->assertOk();

        $order->refresh();
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals(-1, (float) $product->fresh()->stock_quantity);
        $this->assertEquals(1, SystemAlert::where('type', Alerts::NEGATIVE_STOCK)->count());
    }

    public function test_kiosk_order_is_not_left_behind_when_provider_fails()
    {
        $product = $this->product();
        $uuid    = (string) \Illuminate\Support\Str::uuid();
        $this->provider->failOnCreate = true;

        try {
            app(SelfServiceOrderService::class)->createOrder([['product_id' => $product->id, 'quantity' => 1]], 'SS1', $uuid);
            $this->fail('Ожидалось исключение');
        } catch (SelfServiceException $e) {
            $this->assertStringContainsString('недоступна', $e->getMessage());
        }
        $this->assertEquals(0, Order::count());

        // Повтор с тем же client_uuid создаёт заказ заново
        $this->provider->failOnCreate = false;
        $order = app(SelfServiceOrderService::class)->createOrder([['product_id' => $product->id, 'quantity' => 1]], 'SS1', $uuid);
        $this->assertEquals($uuid, $order->uuid);
        $this->assertEquals(1, $order->payments()->count());
    }

    public function test_kiosk_cancel_refused_when_already_paid_at_bank()
    {
        $product = $this->product();
        $order   = app(SelfServiceOrderService::class)->createOrder([['product_id' => $product->id, 'quantity' => 1]], 'SS1');
        $this->provider->cancelOutcome = PaymentProviderInterface::CANCEL_ALREADY_PAID;

        try {
            app(SelfServiceOrderService::class)->cancelOrder($order);
            $this->fail('Ожидалось исключение');
        } catch (SelfServiceException $e) {
            $this->assertStringContainsString('Оплата уже прошла', $e->getMessage());
        }

        $this->assertEquals('paid', $order->fresh()->payment_status);
    }

    public function test_kiosk_order_numbers_are_sequential()
    {
        $product = $this->product();
        $service = app(SelfServiceOrderService::class);

        $first  = $service->createOrder([['product_id' => $product->id, 'quantity' => 1]], 'SS1');
        $second = $service->createOrder([['product_id' => $product->id, 'quantity' => 1]], 'SS2');

        $this->assertEquals('S-1', $first->order_number);
        $this->assertEquals('S-2', $second->order_number);
    }

    // ---------------------------------------------------------------
    // Алерты
    // ---------------------------------------------------------------

    public function test_cash_refund_of_extra_payment_closes_alert()
    {
        $order = $this->onlineOrder();
        $first = $this->payment($order);
        $second = $this->payment($order);
        $this->webhook($this->committedEvent($first));
        $this->webhook($this->committedEvent($second));
        $alert = SystemAlert::where('type', Alerts::PAYMENT_NEEDS_REFUND)->firstOrFail();

        $manager = $this->staff(['alerts.manage']);
        $this->actingAs($manager)->postJson("/api/alerts/{$alert->id}/refund-cash")->assertOk();
        $this->actingAs($manager)->postJson("/api/alerts/{$alert->id}/refund-cash")->assertStatus(409);

        $this->assertTrue($alert->fresh()->is_read);
        $this->assertEquals('refunded', $second->fresh()->status);
        $this->assertEquals('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('financial_transactions', [
            'trackable_type' => Payment::class,
            'trackable_id'   => $second->id,
            'type'           => 'expense',
            'payment_method' => 'cash',
        ]);
    }

    public function test_alerts_require_permission()
    {
        $cashier = $this->staff([]);

        $this->actingAs($cashier)->getJson('/api/alerts')->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Онлайн QR-заказ: для персонала появляется только после оплаты
    // ---------------------------------------------------------------

    private function checkout(string $paymentMethod): Order
    {
        $user    = User::factory()->create();
        $product = $this->product(5, 150);
        $cart    = \App\Models\Cart::factory()->create(['user_id' => $user->id]);
        \App\Models\CartItem::factory()->create([
            'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 150,
        ]);

        $this->actingAs($user)->postJson('/api/orders', [
            'shipping_address' => [
                'first_name' => 'Иван', 'last_name' => 'Иванов', 'country' => 'KG',
                'city' => 'Бишкек', 'address_line_1' => 'ул. Тестовая 1',
            ],
            'payment_method' => $paymentMethod,
        ])->assertStatus(201);

        return Order::latest('id')->first();
    }

    public function test_qr_order_is_announced_to_staff_only_after_payment()
    {
        Event::fake([NewOrderPlaced::class]);

        $order = $this->checkout('mbank');

        Event::assertNotDispatched(NewOrderPlaced::class);
        $this->assertEquals(4, (float) $order->items()->first()->product->fresh()->stock_quantity, 'товар резервируется сразу');

        $payment = $order->payments()->first();
        $this->assertNotNull($payment, 'QR выпускается при оформлении');
        $this->webhook($this->committedEvent($payment))->assertOk();

        Event::assertDispatchedTimes(NewOrderPlaced::class, 1);
        Event::assertDispatched(NewOrderPlaced::class, fn ($e) => $e->order->id === $order->id);

        // Повтор вебхука не объявляет заказ второй раз
        $this->webhook($this->committedEvent($payment))->assertOk();
        Event::assertDispatchedTimes(NewOrderPlaced::class, 1);
    }

    public function test_cash_on_delivery_order_is_announced_immediately()
    {
        Event::fake([NewOrderPlaced::class]);

        $this->checkout('cash');

        Event::assertDispatchedTimes(NewOrderPlaced::class, 1);
    }

    public function test_paying_qr_for_cancelled_order_does_not_announce_it()
    {
        Event::fake([NewOrderPlaced::class]);

        $order   = $this->onlineOrder(['status' => 'cancelled']);
        $payment = $this->payment($order);
        $this->webhook($this->committedEvent($payment))->assertOk();

        Event::assertNotDispatched(NewOrderPlaced::class);
    }

    public function test_admin_list_can_hide_orders_awaiting_qr_payment()
    {
        $manager = $this->staff(['orders.view']);

        $awaiting  = $this->onlineOrder();
        $paid      = $this->onlineOrder(['payment_status' => 'paid', 'status' => 'processing']);
        $cod       = $this->onlineOrder(['payment_method' => 'cash']);
        $noMethod  = $this->onlineOrder(['payment_method' => null]);
        $cancelled = $this->onlineOrder(['status' => 'cancelled']);

        $ids = fn ($response) => collect($response->json('data'))->pluck('id')->sort()->values()->all();

        $hidden = $this->actingAs($manager)->getJson('/api/orders?source=online&hide_awaiting_qr=1')->assertOk();
        $this->assertEquals(
            collect([$paid->id, $cod->id, $noMethod->id, $cancelled->id])->sort()->values()->all(),
            $ids($hidden),
        );

        $all = $this->actingAs($manager)->getJson('/api/orders?source=online')->assertOk();
        $this->assertContains($awaiting->id, $ids($all));
    }

    public function test_customer_still_sees_own_order_awaiting_payment()
    {
        $order    = $this->onlineOrder();
        $customer = $order->user;

        $response = $this->actingAs($customer)->getJson('/api/orders')->assertOk();
        $this->assertContains($order->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_expire_command_default_ttl_is_twenty_minutes()
    {
        $old = $this->onlineOrder();
        $old->forceFill(['created_at' => now()->subMinutes(25)])->save();

        $recent = $this->onlineOrder();
        $recent->forceFill(['created_at' => now()->subMinutes(15)])->save();

        $this->artisan('orders:expire-unpaid')->assertExitCode(0);

        $this->assertEquals('cancelled', $old->fresh()->status);
        $this->assertEquals('pending', $recent->fresh()->status);
    }

    public function test_expire_command_respects_ttl_setting()
    {
        \App\Models\Setting::updateOrCreate(['key' => 'online_order_payment_ttl_minutes'], ['value' => '60']);

        $order = $this->onlineOrder();
        $order->forceFill(['created_at' => now()->subMinutes(25)])->save();

        $this->artisan('orders:expire-unpaid')->assertExitCode(0);

        $this->assertEquals('pending', $order->fresh()->status);
    }

    public function test_order_shows_payment_deadline_only_while_awaiting_qr()
    {
        $awaiting = $this->onlineOrder();
        $deadline = $this->actingAs($awaiting->user)->getJson("/api/orders/{$awaiting->id}")->assertOk()->json('payment_deadline');
        $this->assertNotNull($deadline);
        $this->assertEqualsWithDelta(
            $awaiting->created_at->copy()->addMinutes(20)->timestamp,
            \Carbon\Carbon::parse($deadline)->timestamp,
            1,
        );

        $paid = $this->onlineOrder(['payment_status' => 'paid', 'status' => 'processing']);
        $this->assertNull($this->actingAs($paid->user)->getJson("/api/orders/{$paid->id}")->json('payment_deadline'));

        $cod = $this->onlineOrder(['payment_method' => 'cash']);
        $this->assertNull($this->actingAs($cod->user)->getJson("/api/orders/{$cod->id}")->json('payment_deadline'));
    }

    public function test_new_qr_returns_full_order_for_the_order_page()
    {
        $order = $this->onlineOrder();
        $this->payment($order);

        $response = $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/payment-qr")->assertOk();

        $this->assertCount(1, $response->json('order.items'));
        $this->assertNotNull($response->json('order.payment_deadline'));
    }

    public function test_new_qr_for_cancelled_order_explains_in_russian()
    {
        $order = $this->onlineOrder(['status' => 'cancelled']);

        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/payment-qr")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Заказ отменён: время на оплату истекло или он отменён вручную. Оформите заказ заново.');
    }

    public function test_scheduler_runs_jobs_rarely_enough_for_neon_to_sleep()
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $expire = $events->first(fn ($e) => str_contains($e->command, 'orders:expire-unpaid'));
        $queue  = $events->first(fn ($e) => str_contains($e->command, 'queue:work'));

        $this->assertEquals('*/15 * * * *', $expire->expression);
        $this->assertEquals('0 * * * *', $queue->expression);
    }
}
