<?php

namespace App\Support;

/**
 * Номер телефона Кыргызстана в едином виде +996XXXXXXXXX — по нему покупатель
 * входит на сайт и находится на кассе. Принимает то, как номер обычно
 * вводят: «0555 12-34-56», «555123456», «996555123456», «+996 (555) 123 456».
 */
class Phone
{
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if (strlen($digits) === 12 && str_starts_with($digits, '996')) {
            $digits = substr($digits, 3);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Абонентский номер — 9 цифр, без ведущего нуля
        if (strlen($digits) !== 9 || $digits[0] === '0') {
            return null;
        }

        return '+996' . $digits;
    }

    // Для SMS-шлюза: 996XXXXXXXXX
    public static function digits(string $normalized): string
    {
        return ltrim($normalized, '+');
    }

    // Для показа: +996 555 123 456
    public static function format(?string $normalized): ?string
    {
        if (! $normalized || ! preg_match('/^\+996(\d{3})(\d{3})(\d{3})$/', $normalized, $m)) {
            return $normalized;
        }

        return "+996 {$m[1]} {$m[2]} {$m[3]}";
    }
}
