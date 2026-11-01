<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * SMS-шлюз не принял сообщение. Покупателю — понятный текст, подробности —
 * в лог (в исключении их нет, чтобы не показать внутреннюю ошибку шлюза).
 */
class SmsSendException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Не удалось отправить SMS. Попробуйте ещё раз через минуту.',
        ], 503);
    }
}
