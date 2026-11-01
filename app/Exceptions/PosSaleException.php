<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Продажу на кассе провести нельзя: текст — для кассира. Laravel сам
 * превращает исключение в ответ 422 (метод render). Не ValidationException:
 * в Laravel 8 её message всегда "The given data was invalid.", а касса
 * показывает именно message.
 */
class PosSaleException extends \RuntimeException
{
    public function __construct(string $message, private string $field = 'items', private int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors'  => [$this->field => [$this->getMessage()]],
        ], $this->status);
    }
}
