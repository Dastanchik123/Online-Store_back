<?php

namespace Tests\Support;

use App\Contracts\PaymentProviderInterface;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Models\Order;
use App\Models\Payment;

/**
 * Управляемый провайдер оплаты для тестов: вместо GoPay. Запоминает вызовы
 * и позволяет задать исход отмены и сбой при создании платежа.
 */
class FakePaymentProvider implements PaymentProviderInterface
{
    public string $cancelOutcome = self::CANCEL_CANCELLED;
    public bool $failOnCreate = false;
    public bool $unavailableOnCancel = false;
    public ?string $status = null;

    /** @var int[] id платежей, которые отменяли */
    public array $cancelled = [];

    public function createPayment(Order $order, Payment $payment): array
    {
        if ($this->failOnCreate) {
            throw new \RuntimeException('GoPay недоступен (тест)');
        }

        return [
            'payment_id' => 'fake-' . $payment->id,
            'provider'   => 'fake',
            'status'     => 'pending',
            'qr_value'   => 'fake-qr-' . $payment->id,
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ];
    }

    public function cancelPayment(Payment $payment): string
    {
        if ($this->unavailableOnCancel) {
            throw new PaymentProviderUnavailableException('Платёжная система не ответила (тест).');
        }

        $this->cancelled[] = $payment->id;

        return $this->cancelOutcome;
    }

    public function fetchStatus(Payment $payment): ?string
    {
        return $this->status;
    }
}
