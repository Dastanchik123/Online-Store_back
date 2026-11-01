<?php

namespace App\Services\Debts;

use App\Contracts\PaymentProviderInterface;
use App\Exceptions\DebtPaymentException;
use App\Models\CustomerDebt;
use App\Models\DebtPayment;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Support\Facades\DB;

/**
 * Погашение долга: наличными — сразу, безналом — QR GoPay. QR-выплата
 * записывается в долг только после подтверждения банка
 * (PaymentSettlementService → settleQrPayment), как и QR на кассе.
 */
class DebtPaymentService
{
    public const METHOD_CASH = 'cash';
    public const METHOD_QR   = 'qr';

    public function __construct(private PaymentProviderInterface $paymentProvider)
    {
    }

    /**
     * Принять наличные по долгу.
     *
     * @throws DebtPaymentException
     */
    public function payCash(CustomerDebt $debt, float $amount, ?int $userId): CustomerDebt
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($debt, $amount, $userId) {
            // Блокировка: двойное нажатие «Принять» не запишет платёж дважды —
            // второй запрос увидит уже уменьшенный остаток
            $debt = CustomerDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $this->ensurePayable($debt, $amount);
            $this->apply($debt, $amount, self::METHOD_CASH, $userId);

            return $debt;
        });
    }

    /**
     * Выпустить QR на погашение долга. Прежний неоплаченный QR этого долга
     * гасится у провайдера, чтобы по нему нельзя было заплатить второй раз.
     *
     * Вызывать вне транзакции: обращение к провайдеру — внешний HTTP-запрос.
     *
     * @throws DebtPaymentException
     * @throws \App\Exceptions\PaymentProviderUnavailableException
     */
    public function createQr(CustomerDebt $debt, float $amount): Payment
    {
        $amount = round($amount, 2);

        if (! $debt->order_id) {
            throw new DebtPaymentException('Оплата по QR доступна только для долга по заказу. Примите наличные.');
        }

        $this->cancelPendingQr($debt);

        $payment = DB::transaction(function () use ($debt, $amount) {
            $debt = CustomerDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $this->ensurePayable($debt, $amount);

            return Payment::create([
                'order_id'         => $debt->order_id,
                'customer_debt_id' => $debt->id,
                'payment_method'   => self::METHOD_QR,
                'status'           => 'pending',
                'amount'           => $amount,
                'currency'         => 'SOM',
            ]);
        });

        try {
            $payload = $this->paymentProvider->createPayment($debt->order, $payment);
        } catch (\Throwable $e) {
            $payment->update(['status' => 'failed']);
            throw new DebtPaymentException('QR-оплата сейчас недоступна. Примите оплату наличными.', 503);
        }

        $payment->update([
            'transaction_id'  => $payload['payment_id'],
            'payment_details' => json_encode($payload),
        ]);

        return $payment->fresh();
    }

    /**
     * Состояние QR-погашения для окна ожидания. С $askProvider — спросить
     * провайдера (если вебхук задерживается).
     */
    public function qrStatus(Payment $payment, bool $askProvider = false): Payment
    {
        if ($askProvider && in_array($payment->status, ['pending', 'processing'], true)
            && $this->paymentProvider->fetchStatus($payment) === PaymentProviderInterface::STATUS_PAID) {
            app(PaymentSettlementService::class)->settleCommitted($payment->transaction_id);
        }

        return $payment->fresh();
    }

    /**
     * Отменить QR-погашение (покупатель передумал, QR истёк). Если банк уже
     * принял оплату — она проводится, и вызывающий увидит status completed.
     *
     * @throws \App\Exceptions\PaymentProviderUnavailableException
     */
    public function cancelQr(Payment $payment): Payment
    {
        if (in_array($payment->status, ['pending', 'processing'], true)) {
            $this->cancelProviderPayment($payment);
        }

        return $payment->fresh();
    }

    /**
     * Банк подтвердил QR-погашение. Вызывается из PaymentSettlementService
     * внутри транзакции с заблокированным платежом. Возвращает false, если
     * долг к этому моменту закрыт/отменён или сумма больше остатка — тогда
     * деньги нужно вернуть покупателю.
     */
    public function settleQrPayment(Payment $payment): bool
    {
        $debt = CustomerDebt::whereKey($payment->customer_debt_id)->lockForUpdate()->first();

        try {
            if (! $debt) {
                throw new DebtPaymentException('Долг не найден.');
            }
            $this->ensurePayable($debt, (float) $payment->amount);
        } catch (DebtPaymentException $e) {
            return false;
        }

        $this->apply($debt, (float) $payment->amount, self::METHOD_QR, null);

        return true;
    }

    // ---------------------------------------------------------------

    private function ensurePayable(CustomerDebt $debt, float $amount): void
    {
        if (! in_array($debt->status, ['active', 'partial'], true)) {
            throw new DebtPaymentException('Долг уже закрыт или отменён — принимать оплату не по чему.', 409);
        }
        if ($amount <= 0) {
            throw new DebtPaymentException('Сумма должна быть больше 0.');
        }
        // Сравнение в тыйынах, как на кассе
        if (round($amount * 100) > round((float) $debt->remaining_amount * 100)) {
            throw new DebtPaymentException("Сумма больше остатка долга ({$debt->remaining_amount} сом).");
        }
    }

    // Выплата по заблокированному долгу: запись выплаты, остаток, статус
    // заказа и приход в кассу
    private function apply(CustomerDebt $debt, float $amount, string $method, ?int $userId): DebtPayment
    {
        $payment = DebtPayment::create([
            'customer_debt_id' => $debt->id,
            'amount'           => $amount,
            'payment_method'   => $method,
        ]);

        $debt->paid_amount      = round((float) $debt->paid_amount + $amount, 2);
        $debt->remaining_amount = round((float) $debt->remaining_amount - $amount, 2);

        if ($debt->remaining_amount <= 0) {
            $debt->status           = 'paid';
            $debt->remaining_amount = 0;

            if ($debt->order_id) {
                $debt->order()->update(['payment_status' => 'paid']);
            }
        } else {
            $debt->status = 'partial';
        }
        $debt->save();

        FinancialTransaction::create([
            'user_id'        => $userId,
            'type'           => 'income',
            'amount'         => $amount,
            'category'       => 'debt_payment',
            'trackable_type' => DebtPayment::class,
            'trackable_id'   => $payment->id,
            'description'    => $method === self::METHOD_QR
                ? "Оплата долга по QR (GoPay) от пользователя #{$debt->user_id}"
                : "Оплата долга от пользователя #{$debt->user_id}",
            'payment_method' => $method,
        ]);

        return $payment;
    }

    private function cancelPendingQr(CustomerDebt $debt): void
    {
        $pending = Payment::where('customer_debt_id', $debt->id)
            ->whereIn('status', ['pending', 'processing'])
            ->get();

        foreach ($pending as $payment) {
            $this->cancelProviderPayment($payment);
        }
    }

    private function cancelProviderPayment(Payment $payment): void
    {
        if (! $payment->transaction_id) {
            $payment->update(['status' => 'failed']);

            return;
        }

        if ($this->paymentProvider->cancelPayment($payment) === PaymentProviderInterface::CANCEL_ALREADY_PAID) {
            // Уже оплачен — проводим, новый QR выпустится на меньший остаток
            app(PaymentSettlementService::class)->settleCommitted($payment->transaction_id);

            return;
        }

        // Только если вебхук не успел провести этот платёж
        Payment::whereKey($payment->id)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed']);
    }
}
