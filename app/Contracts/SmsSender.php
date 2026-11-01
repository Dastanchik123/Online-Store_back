<?php

namespace App\Contracts;

/**
 * Отправка SMS. Реализации: NikitaSmsSender (smspro.nikita.kg) и LogSmsSender
 * (разработка/тесты — текст пишется в лог, SMS не уходит).
 */
interface SmsSender
{
    /**
     * @param  string  $phone  номер в виде +996XXXXXXXXX
     *
     * @throws \App\Exceptions\SmsSendException SMS не принято шлюзом
     */
    public function send(string $phone, string $text): void;
}
