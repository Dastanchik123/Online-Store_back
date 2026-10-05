<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\GoPayClient;
use App\Services\SelfServiceOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Принимает legacy callback_url от GoPay (срабатывает только на
 * payment.committed). Без auth-мидлвара — подлинность запроса подтверждает
 * HMAC-подпись в заголовках, а не токен/сессия.
 */
class GoPayWebhookController extends Controller
{
    public function __construct(private SelfServiceOrderService $orders)
    {
    }

    public function handle(Request $request)
    {
        $rawBody   = $request->getContent();
        $nonce     = $request->header('GoPay-Nonce');
        $signature = $request->header('GoPay-Signature');

        if (! GoPayClient::verifyWebhookSignature((string) config('services.gopay.secret_key'), $nonce, $rawBody, $signature)) {
            Log::warning('GoPay webhook: подпись не совпала, запрос отклонён');

            return response()->json(['message' => 'invalid signature'], 400);
        }

        $payload = json_decode($rawBody, true) ?: [];

        if (($payload['status'] ?? null) === 'COMMITTED' && ! empty($payload['payment_id'])) {
            $this->orders->confirmPaymentFromGopay($payload['payment_id']);
        }

        // Любой другой статус (CREATED и т.д.) по legacy callback_url не
        // приходит — отвечаем 200, чтобы GoPay не повторял доставку.
        return response()->json(['status' => 'ok']);
    }
}
