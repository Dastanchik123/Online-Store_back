<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * SMS не отправляется — текст пишется в storage/logs/laravel.log. Для
 * разработки без договора со шлюзом и для тестов. Код входа ищите в логе
 * по строке «SMS (не отправлено)».
 */
class LogSmsSender implements SmsSender
{
    /** @var array<int, array{phone: string, text: string}> отправленное за запрос (для тестов) */
    public array $sent = [];

    public function send(string $phone, string $text): void
    {
        $this->sent[] = ['phone' => $phone, 'text' => $text];
        Log::info("SMS (не отправлено) на {$phone}: {$text}");
    }
}
