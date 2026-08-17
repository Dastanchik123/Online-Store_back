<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Временный провайдер оплаты self-service кассы — до подключения реального
 * банка/платёжного шлюза (первый этап §35 из ТЗ). QR кодирует зашифрованную
 * ссылку на страницу подтверждения оплаты в этом же Nuxt-приложении
 * (/self-service/pay/{token}): покупатель сканирует своим телефоном и сам
 * подтверждает оплату — это демо-мост, а не настоящий банковский платёж.
 *
 * Токен уникален на заказ (никогда не переиспользуется между заказами) и
 * содержит собственный expires_at — тот же принцип, что уже используется
 * для QR корзины в CartController::checkoutQr.
 */
class TemporaryQrPaymentProvider implements PaymentProviderInterface
{
    public function createPayment(Order $order, Payment $payment): array
    {
        $timeoutSeconds = (int) (Setting::where('key', 'self_service_payment_timeout')->value('value') ?: 300);
        $expiresAt      = now()->addSeconds($timeoutSeconds);
        $paymentId      = (string) Str::uuid();

        $token = Crypt::encryptString(json_encode([
            'payment_id' => $paymentId,
            'order_id'   => $order->id,
            'exp'        => $expiresAt->timestamp,
        ]));

        return [
            'payment_id' => $paymentId,
            'provider'   => 'temporary_qr',
            'status'     => 'pending',
            'qr_value'   => rtrim(config('app.url'), '/') . '/self-service/pay/' . $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function cancelPayment(Payment $payment): void
    {
        // Временному провайдеру нечего отменять на стороне банка — статус
        // платежа обновляет сам SelfServiceOrderService.
    }
}
