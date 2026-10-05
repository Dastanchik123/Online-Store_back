<?php

namespace App\Exceptions;

/**
 * Ожидаемые бизнес-отказы возврата поставщику (недостаточно доступного
 * количества, недостаточно остатка, документ уже обработан и т.п.) —
 * несут HTTP-статус, чтобы контроллер мог единообразно превратить их
 * в JSON-ответ уже после отката DB::transaction.
 */
class SupplierReturnException extends \RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
