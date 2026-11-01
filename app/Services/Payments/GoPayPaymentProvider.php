<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Реальный провайдер оплаты через GoPay (ELQR QR). Заменяет
 * TemporaryQrPaymentProvider — биндинг переключён в AppServiceProvider,
 * SelfServiceOrderService/контроллер/фронт ничего не знают о смене.
 *
 * order_id для GoPay — "pay-{id}-{created_at unix}" записи Payment, а не
 * payment_id из createPayment: GoPay требует order_id ≤ 32 символов, а UUID
 * не влезет. Метка времени создания нужна, потому что GoPay помнит order_id
 * навсегда: после пересоздания БД id платежей начинаются заново, и голый
 * "pay-{id}" отклонялся как «Идентификатор заказа уже существует». Для одной
 * записи Payment значение не меняется — повтор запроса не создаст второй счёт.
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
            'order_id'    => 'pay-' . $payment->id . '-' . ($payment->created_at ?? now())->getTimestamp(),
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

    public function cancelPayment(Payment $payment): string
    {
        // Платёж так и не дошёл до GoPay (сбой при создании) — отменять нечего
        if (! $payment->transaction_id) {
            return self::CANCEL_CANCELLED;
        }

        try {
            $this->client->cancelPayment(['payment_id' => $payment->transaction_id]);

            return self::CANCEL_CANCELLED;
        } catch (GoPayException $e) {
            if ($e->gopayCode !== GoPayException::CANNOT_CANCEL) {
                // 0015 (банк недоступен), сеть и т.п. — QR может быть ещё жив
                Log::warning("GoPay cancelPayment #{$payment->id} failed: {$e->getMessage()}");
                throw new PaymentProviderUnavailableException('Платёжная система не ответила на отмену. Повторите через несколько секунд.');
            }
        }

        // 0013: отменить нельзя — платёж уже в терминальном статусе или банк
        // его подтвердил. Какой именно случай, узнаём запросом статуса.
        try {
            $status = $this->client->queryPayment(['payment_id' => $payment->transaction_id])['status'] ?? null;
        } catch (GoPayException $e) {
            throw new PaymentProviderUnavailableException('Не удалось узнать статус оплаты. Повторите через несколько секунд.');
        }

        if ($status === 'COMMITTED') {
            return self::CANCEL_ALREADY_PAID;
        }
        if (in_array($status, ['CANCELLED', 'EXPIRED', 'FAILED'], true)) {
            return self::CANCEL_CANCELLED;
        }

        // CREATED при 0013 — банк уже подтвердил, GoPay дообрабатывает оплату
        throw new PaymentProviderUnavailableException('Оплата уже проходит в банке. Подождите несколько секунд и повторите.');
    }

    public function fetchStatus(Payment $payment): ?string
    {
        if (! $payment->transaction_id) {
            return null;
        }

        try {
            $status = $this->client->queryPayment(['payment_id' => $payment->transaction_id])['status'] ?? null;
        } catch (GoPayException $e) {
            Log::warning("GoPay queryPayment #{$payment->id} failed: {$e->getMessage()}");

            return null;
        }

        return match ($status) {
            'COMMITTED'                       => self::STATUS_PAID,
            'CREATED'                         => self::STATUS_OPEN,
            'CANCELLED', 'EXPIRED', 'FAILED'  => self::STATUS_CLOSED,
            default                           => null,
        };
    }
}
