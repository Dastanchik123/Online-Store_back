<?php

namespace App\Support;

class Ean13
{
    /**
     * Контрольная цифра EAN-13 для 12 цифр данных (сумма по чётным позициям
     * с весом 1, по нечётным — с весом 3, дополнение до кратного 10).
     */
    public static function checkDigit(string $digits12): int
    {
        $sum = 0;
        foreach (str_split($digits12) as $i => $digit) {
            $sum += ($i % 2 === 0) ? (int) $digit : (int) $digit * 3;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
