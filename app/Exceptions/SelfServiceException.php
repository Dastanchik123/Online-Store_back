<?php

namespace App\Exceptions;

// Доменная ошибка self-service кассы (нет остатка, истёк QR, и т.п.) —
// сообщение уже написано понятным покупателю языком и безопасно отдаётся
// в JSON-ответе как есть, в отличие от системных исключений.
class SelfServiceException extends \RuntimeException
{
}
