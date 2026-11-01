<?php

namespace App\Services\Payments;

/**
 * Ошибка GoPay API с кодом из ответа ("0013", "0015", "4041"...). Для сбоя
 * сети, когда ответа нет вовсе, код — "NETWORK".
 */
class GoPayException extends \RuntimeException
{
    public const NETWORK = 'NETWORK';

    // Отменить нельзя: платёж уже в терминальном статусе или банк его подтвердил
    public const CANNOT_CANCEL = '0013';

    public string $gopayCode;

    public function __construct(string $gopayCode, string $message)
    {
        parent::__construct($message);
        $this->gopayCode = $gopayCode;
    }
}
