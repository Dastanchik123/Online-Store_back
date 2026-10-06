<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\GoPayClient;
use App\Services\Payments\OrderQrPaymentService;
use App\Services\SelfServiceOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Принимает legacy callback_url от GoPay (срабатывает только на
 * payment.committed). Без auth-мидлвара — подлинность запроса подтверждает
 * HMAC-подпись в заголовках, а не токен/сессия. Один вебхук обслуживает и
 * self-service кассу, и обычные онлайн-заказы — какой сервис подтверждения
 * вызвать, определяется по channel заказа, которому принадлежит платёж.
 */
class GoPayWebhookController extends Controller
{
    public function __construct(
        private SelfServiceOrderService $selfServiceOrders,
        private OrderQrPaymentService $onlineOrders,
    ) {
    }

    public function handle(Request $request)
    {
        $rawBody   = $request->getContent();
        $nonce     = $request->header('GoPay-Nonce');
        $signature = $request->header('GoPay-Signature');

        // Внимание: подпись вебхука считается отдельным webhook_secret
        // (Developer → Webhooks в кабинете GoPay), а НЕ secret_key от API —
        // несмотря на формулировку доков про "тот же алгоритм".
        if (! GoPayClient::verifyWebhookSignature((string) config('services.gopay.webhook_secret'), $nonce, $rawBody, $signature)) {
            Log::warning('GoPay webhook: подпись не совпала, запрос отклонён');

            return response()->json(['message' => 'invalid signature'], 400);
        }

        $payload = json_decode($rawBody, true) ?: [];

        if (($payload['status'] ?? null) === 'COMMITTED' && ! empty($payload['payment_id'])) {
            $payment = Payment::where('transaction_id', $payload['payment_id'])->first();

            if ($payment && $payment->order?->channel === 'self_service') {
                $this->selfServiceOrders->confirmPaymentFromGopay($payload['payment_id']);
            } else {
                $this->onlineOrders->confirmFromGopay($payload['payment_id']);
            }
        }

        // Любой другой статус (CREATED и т.д.) по legacy callback_url не
        // приходит — отвечаем 200, чтобы GoPay не повторял доставку.
        return response()->json(['status' => 'ok']);
    }
}
