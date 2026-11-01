<?php

namespace App\Exceptions;

/**
 * Платёжный провайдер сейчас не может выполнить операцию (нет связи, банк
 * недоступен, оплата ещё дообрабатывается). Состояние платежа не изменилось —
 * операцию можно безопасно повторить чуть позже.
 */
class PaymentProviderUnavailableException extends \RuntimeException
{
}
