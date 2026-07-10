<?php

namespace App\Support;

class NumberToWordsRu
{
    private const UNITS = [
        ['', '', ''],
        ['один', 'одна', 'одно'],
        ['два', 'две', 'два'],
        ['три', 'три', 'три'],
        ['четыре', 'четыре', 'четыре'],
        ['пять', 'пять', 'пять'],
        ['шесть', 'шесть', 'шесть'],
        ['семь', 'семь', 'семь'],
        ['восемь', 'восемь', 'восемь'],
        ['девять', 'девять', 'девять'],
    ];

    private const TEENS = [
        'десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать',
        'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать',
    ];

    private const TENS = [
        '', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят',
        'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто',
    ];

    private const HUNDREDS = [
        '', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот',
        'шестьсот', 'семьсот', 'восемьсот', 'девятьсот',
    ];

    public static function amountToWords(float $sum): string
    {
        $sum = round($sum, 2);
        $som = (int) floor($sum);
        $tyiyn = (int) round(($sum - $som) * 100);

        $somWords = self::integerToWords($som);
        $somUnit = self::pluralize($som, ['сом', 'сома', 'сомов']);
        $tyiynStr = str_pad((string) $tyiyn, 2, '0', STR_PAD_LEFT);
        $tyiynUnit = self::pluralize($tyiyn, ['тыйын', 'тыйына', 'тыйынов']);

        $capitalized = mb_strtoupper(mb_substr($somWords, 0, 1)) . mb_substr($somWords, 1);

        return "{$capitalized} {$somUnit} {$tyiynStr} {$tyiynUnit}";
    }

    public static function countToWords(int $n): string
    {
        if ($n === 0) {
            return 'ноль';
        }

        $h = intdiv($n % 1000, 100);
        $t = $n % 100;
        $t1 = intdiv($t, 10);
        $t2 = $t % 10;

        $words = [];
        if ($h > 0) {
            $words[] = self::HUNDREDS[$h];
        }
        if ($t >= 10 && $t <= 19) {
            $words[] = self::TEENS[$t - 10];
        } else {
            if ($t1 > 0) {
                $words[] = self::TENS[$t1];
            }
            if ($t2 > 0) {
                $words[] = self::UNITS[$t2][2]; // средний род: "одно", "два"...
            }
        }

        return implode(' ', array_filter($words)) ?: 'ноль';
    }

    private static function threeDigits(int $n, int $gender): array
    {
        $words = [];
        $h = intdiv($n, 100);
        $t = $n % 100;
        $t1 = intdiv($t, 10);
        $t2 = $t % 10;

        if ($h > 0) {
            $words[] = self::HUNDREDS[$h];
        }

        if ($t >= 10 && $t <= 19) {
            $words[] = self::TEENS[$t - 10];
        } else {
            if ($t1 > 0) {
                $words[] = self::TENS[$t1];
            }
            if ($t2 > 0) {
                $words[] = self::UNITS[$t2][$gender];
            }
        }

        return $words;
    }

    public static function pluralize(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        if ($n > 10 && $n < 20) {
            return $forms[2];
        }
        if ($n1 > 1 && $n1 < 5) {
            return $forms[1];
        }
        if ($n1 === 1) {
            return $forms[0];
        }

        return $forms[2];
    }

    private static function integerToWords(int $num): string
    {
        if ($num === 0) {
            return 'ноль';
        }

        $billions = intdiv($num, 1000000000);
        $millions = intdiv($num % 1000000000, 1000000);
        $thousands = intdiv($num % 1000000, 1000);
        $rest = $num % 1000;

        $parts = [];

        if ($billions > 0) {
            $parts = array_merge($parts, self::threeDigits($billions, 0));
            $parts[] = self::pluralize($billions, ['миллиард', 'миллиарда', 'миллиардов']);
        }
        if ($millions > 0) {
            $parts = array_merge($parts, self::threeDigits($millions, 0));
            $parts[] = self::pluralize($millions, ['миллион', 'миллиона', 'миллионов']);
        }
        if ($thousands > 0) {
            $parts = array_merge($parts, self::threeDigits($thousands, 1)); // тысяча - женский род
            $parts[] = self::pluralize($thousands, ['тысяча', 'тысячи', 'тысяч']);
        }
        if ($rest > 0 || empty($parts)) {
            $parts = array_merge($parts, self::threeDigits($rest, 0));
        }

        return implode(' ', array_filter($parts));
    }
}
