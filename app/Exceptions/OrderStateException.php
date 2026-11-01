<?php

namespace App\Exceptions;

/**
 * Операцию с заказом нельзя выполнить в его текущем состоянии (статус,
 * оплата). Текст — для показа пользователю.
 */
class OrderStateException extends \RuntimeException
{
}
