<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\GoPayClient;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления GoPay об оплате. Без auth-мидлвара — подлинность запроса
 * подтверждает HMAC-подпись в заголовках (секрет webhook_secret).
 *
 * Принимает оба формата GoPay:
 *  - events_url (рекомендуемый): {"event": "payment.committed", "data": {...}}
 *    с событиями payment.committed / failed / expired / cancelled, test.ping;
 *  - legacy callback_url: плоский {"payment_id", "status": "COMMITTED", ...}.
 *
 * Обработка идемпотентна: GoPay повторяет доставку до 2xx, и одно и то же
 * событие может прийти по обоим каналам — PaymentSettlementService это
 * учитывает. На любое корректно подписанное событие отвечаем 200.
 */
class GoPayWebhookController extends Controller
{
    private const CLOSED_EVENTS = ['payment.failed', 'payment.expired', 'payment.cancelled'];

    public function __construct(private PaymentSettlementService $settlement)
    {
    }

    public function handle(Request $request)
    {
        $rawBody   = $request->getContent();
        $nonce     = $request->header('GoPay-Nonce');
        $signature = $request->header('GoPay-Signature');

        // Подпись вебхука считается отдельным webhook_secret (Developer →
        // Webhooks в кабинете GoPay), а НЕ secret_key от API. Без секрета
        // HMAC считался бы пустым ключом — такую подпись может подделать кто
        // угодно и пометить любой заказ оплаченным.
        $secret = (string) config('services.gopay.webhook_secret');
        if ($secret === '') {
            Log::error('GoPay webhook: GOPAY_WEBHOOK_SECRET не задан, вебхук отклонён');

            return response()->json(['message' => 'webhook not configured'], 503);
        }

        if (! GoPayClient::verifyWebhookSignature($secret, $nonce, $rawBody, $signature)) {
            Log::warning('GoPay webhook: подпись не совпала, запрос отклонён');

            return response()->json(['message' => 'invalid signature'], 400);
        }

        $payload = json_decode($rawBody, true) ?: [];

        [$event, $data] = isset($payload['event'])
            ? [(string) $payload['event'], (array) ($payload['data'] ?? [])]
            : [$this->legacyEvent($payload), $payload];

        $paymentId = $data['payment_id'] ?? null;

        if ($event === 'payment.committed' && $paymentId) {
            $amount = isset($data['amount']) ? (string) $data['amount'] : null;
            $this->settlement->settleCommitted($paymentId, $amount);
        } elseif (in_array($event, self::CLOSED_EVENTS, true) && $paymentId) {
            $this->settlement->markClosed($paymentId);
        }

        // test.ping, receipt.sent и прочие события — просто подтверждаем
        return response()->json(['status' => 'ok']);
    }

    // Legacy callback_url присылает только COMMITTED (плоский payload)
    private function legacyEvent(array $payload): string
    {
        return match ($payload['status'] ?? null) {
            'COMMITTED' => 'payment.committed',
            'FAILED'    => 'payment.failed',
            default     => 'unknown',
        };
    }
}
