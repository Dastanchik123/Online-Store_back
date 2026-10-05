<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

/**
 * HTTP-клиент GoPay (ELQR). Каждый запрос подписывается отдельным nonce —
 * dataStr сериализуется один раз и используется как для подписи, так и как
 * тело запроса, иначе подпись и тело могут не совпасть байт-в-байт.
 */
class GoPayClient
{
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

    public function cancelPayment(array $data): array
    {
        return $this->request('/v1/payments/cancel', $data);
    }

    private function request(string $path, array $data): array
    {
        $dataStr   = json_encode($data, JSON_UNESCAPED_UNICODE);
        $nonce     = bin2hex(random_bytes(16));
        $signature = self::sign($this->secretKey, $nonce, $dataStr);

        $response = Http::withHeaders([
            'GoPay-Api-Key'   => $this->apiKey,
            'GoPay-Nonce'     => $nonce,
            'GoPay-Signature' => $signature,
        ])->withBody($dataStr, 'application/json')
            ->post(rtrim($this->baseUrl, '/') . $path);

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'OK') {
            $message = $json['error_message'] ?? ('GoPay HTTP ' . $response->status());
            throw new \RuntimeException("GoPay API error ({$path}): {$message}");
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
