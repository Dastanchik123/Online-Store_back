<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use App\Exceptions\SmsSendException;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SMS через smspro.nikita.kg (XML API, POST /api/message).
 *
 * NIKITA_SMS_TEST=true — шлюз проверяет запрос (логин, отправитель, номер),
 * но SMS не отправляет и не списывает (ответ status 11). Код в этом режиме
 * дублируется в лог, чтобы можно было войти.
 */
class NikitaSmsSender implements SmsSender
{
    // Статусы ответа шлюза
    private const STATUS_OK   = '0';
    private const STATUS_TEST = '11';

    private const ERRORS = [
        '1'  => 'ошибка в формате запроса',
        '2'  => 'неверный логин или пароль',
        '3'  => 'недопустимый IP-адрес сервера',
        '4'  => 'недостаточно средств на счету',
        '5'  => 'имя отправителя не одобрено',
        '6'  => 'сообщение заблокировано по стоп-словам',
        '7'  => 'некорректный номер телефона',
        '8'  => 'неверный формат времени отправки',
        '9'  => 'шлюз перегружен, повторите позже',
        '10' => 'повтор id отправки',
    ];

    public function __construct(
        private string $login,
        private string $password,
        private string $sender,
        private bool $test = false,
        private string $url = 'https://smspro.nikita.kg/api/message',
    ) {
    }

    public function send(string $phone, string $text): void
    {
        // id — до 12 латинских букв/цифр, уникален на каждую отправку
        $id = Str::upper(Str::random(12));

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><message/>');
        $xml->addChild('login', htmlspecialchars($this->login, ENT_XML1));
        $xml->addChild('pwd', htmlspecialchars($this->password, ENT_XML1));
        $xml->addChild('id', $id);
        $xml->addChild('sender', htmlspecialchars($this->sender, ENT_XML1));
        $xml->addChild('text', htmlspecialchars($text, ENT_XML1));
        $xml->addChild('phones')->addChild('phone', Phone::digits($phone));
        if ($this->test) {
            $xml->addChild('test', '1');
        }

        try {
            $response = Http::timeout(15)
                ->withBody($xml->asXML(), 'application/xml; charset=UTF-8')
                ->post($this->url);
        } catch (\Throwable $e) {
            Log::error('SMS Nikita: шлюз недоступен — ' . $e->getMessage(), ['id' => $id]);
            throw new SmsSendException('Шлюз недоступен');
        }

        $status = $this->status((string) $response->body());

        if ($status === self::STATUS_OK) {
            return;
        }
        if ($status === self::STATUS_TEST) {
            Log::info("SMS Nikita (тестовый режим, не отправлено) на {$phone}: {$text}");

            return;
        }

        $reason = self::ERRORS[$status] ?? "неизвестный ответ (HTTP {$response->status()})";
        Log::error("SMS Nikita: не отправлено — {$reason}", ['id' => $id, 'status' => $status]);
        throw new SmsSendException($reason);
    }

    private function status(string $body): ?string
    {
        try {
            $xml = @simplexml_load_string($body);
        } catch (\Throwable $e) {
            $xml = false;
        }

        return $xml !== false && isset($xml->status) ? trim((string) $xml->status) : null;
    }
}
