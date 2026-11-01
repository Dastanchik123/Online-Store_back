<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Оплату по долгу принять нельзя: текст — для сотрудника на странице
 * «Долги». Laravel сам превращает исключение в ответ (метод render).
 */
class DebtPaymentException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 422, private string $field = 'amount')
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
