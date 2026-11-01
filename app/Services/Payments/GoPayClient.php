<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-клиент GoPay (ELQR). Каждый запрос подписывается отдельным nonce —
 * dataStr сериализуется один раз и используется как для подписи, так и как
 * тело запроса, иначе подпись и тело могут не совпасть байт-в-байт.
 */
class GoPayClient
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $secretKey,
    ) {
    }

    public function createPayment(array $data): array
    {
        return $this->request('/v1/payments', $data);
    }

    // Идемпотентна: повторная отмена уже отменённого платежа — OK
    public function cancelPayment(array $data): array
    {
        return $this->request('/v1/payments/cancel', $data);
    }

    public function queryPayment(array $data): array
    {
        return $this->request('/v1/payments/query', $data);
    }

    private function request(string $path, array $data): array
    {
        $dataStr   = json_encode($data, JSON_UNESCAPED_UNICODE);
        $nonce     = bin2hex(random_bytes(16));
        $signature = self::sign($this->secretKey, $nonce, $dataStr);

        try {
            $response = Http::withHeaders([
                'GoPay-Api-Key'   => $this->apiKey,
                'GoPay-Nonce'     => $nonce,
                'GoPay-Signature' => $signature,
            ])->timeout(self::TIMEOUT_SECONDS)
                ->withBody($dataStr, 'application/json')
                ->post(rtrim($this->baseUrl, '/') . $path);
        } catch (ConnectionException $e) {
            throw new GoPayException(GoPayException::NETWORK, "GoPay недоступен ({$path}): {$e->getMessage()}");
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'OK') {
            $code    = is_array($json) ? (string) ($json['code'] ?? 'HTTP' . $response->status()) : 'HTTP' . $response->status();
            $message = $json['error_message'] ?? ('GoPay HTTP ' . $response->status());
            throw new GoPayException($code, "GoPay API error ({$path}): {$message}");
        }

        return $json['data'] ?? [];
    }

    public static function sign(string $secret, string $nonce, string $dataStr): string
    {
        return strtoupper(hash_hmac('sha512', $nonce . "\n" . $dataStr . "\n", $secret));
    }

    public static function verifyWebhookSignature(string $secret, ?string $nonce, string $rawBody, ?string $signature): bool
    {
        if (! $nonce || ! $signature) {
            return false;
        }

        $expected = self::sign($secret, $nonce, $rawBody);

        return hash_equals($expected, strtoupper($signature));
    }
}
