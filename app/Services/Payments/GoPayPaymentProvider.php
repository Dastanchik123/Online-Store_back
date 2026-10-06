<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Реальный провайдер оплаты через GoPay (ELQR QR). Заменяет
 * TemporaryQrPaymentProvider — биндинг переключён в AppServiceProvider,
 * SelfServiceOrderService/контроллер/фронт ничего не знают о смене.
 *
 * order_id для GoPay — "pay-{id}" записи Payment (у неё уже есть
 * гарантированно уникальный автоинкрементный id), а не payment_id из
 * createPayment: GoPay требует order_id ≤ 32 символов, а тут UUID не влезет.
 */
class GoPayPaymentProvider implements PaymentProviderInterface
{
    private GoPayClient $client;

    public function __construct()
    {
        $this->client = new GoPayClient(
            config('services.gopay.base_url'),
            config('services.gopay.api_key'),
            config('services.gopay.secret_key'),
        );
    }

    public function createPayment(Order $order, Payment $payment): array
    {
        // GoPay требует lifetime >= 300 секунд. Self-service кассу можно
        // настраивать (но не короче 300с); обычный онлайн-заказ — всегда
        // ровно 5 минут.
        $timeoutSeconds = $order->channel === 'self_service'
            ? max(300, (int) (Setting::where('key', 'self_service_payment_timeout')->value('value') ?: 300))
            : 300;

        $requestData = [
            'order_id'    => 'pay-' . $payment->id,
            'amount'      => number_format((float) $payment->amount, 2, '.', ''),
            'description' => "Заказ #{$order->order_number}",
            'lifetime'    => $timeoutSeconds,
        ];

        $appUrl = rtrim((string) config('app.url'), '/');
        if (str_starts_with($appUrl, 'https://')) {
            $requestData['callback_url'] = $appUrl . '/api/self-service/gopay/webhook';
        } else {
            // GoPay принимает callback_url только https — на http(localhost)
            // окружении уведомление не настроить, используется дефолтный
            // callback_url мерчанта из личного кабинета GoPay (если задан).
            Log::warning('GoPay: APP_URL не https, callback_url не передан — настройте дефолтный callback_url в кабинете GoPay.');
        }

        $data = $this->client->createPayment($requestData);

        return [
            'payment_id'   => $data['payment_id'],
            'provider'     => 'gopay',
            'status'       => 'pending',
            // qr_data — EMVCO-пэйлоад специально для самостоятельной отрисовки
            // QR на фронте (там уже есть QRCode.toDataURL(qr_value)).
            'qr_value'     => $data['qr_data'] ?? $data['checkout_url'],
            'checkout_url' => $data['checkout_url'] ?? null,
            'expires_at'   => $data['expires_at'],
        ];
    }

    public function cancelPayment(Payment $payment): void
    {
        if (! $payment->transaction_id) {
            return;
        }

        try {
            $this->client->cancelPayment(['payment_id' => $payment->transaction_id]);
        } catch (\Throwable $e) {
            // Платёж мог уже уйти в терминальный статус на стороне GoPay
            // (например, клиент успел оплатить) — не роняем отмену заказа.
            Log::warning('GoPay cancelPayment failed: ' . $e->getMessage());
        }
    }
}
