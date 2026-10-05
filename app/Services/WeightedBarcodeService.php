<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use App\Support\Ean13;

/**
 * Штрихкод весового товара: [prefix(2)] [product_id(5)] [вес в граммах(5)] [check digit(1)] = 13 цифр.
 * Формат хранится глобально (Setting), а не на товаре — POS должен распознать
 * тип штрихкода раньше, чем узнает, какой это товар.
 */
class WeightedBarcodeService
{
    private const DEFAULT_PREFIX = '21';
    private const DEFAULT_WEIGHT_DIGITS = 5;
    private const PRODUCT_ID_DIGITS = 5;

    public function prefix(): string
    {
        return Setting::where('key', 'weighted_barcode_prefix')->value('value') ?: self::DEFAULT_PREFIX;
    }

    public function weightDigits(): int
    {
        $value = Setting::where('key', 'weighted_barcode_weight_digits')->value('value');
        return $value !== null && $value !== '' ? (int) $value : self::DEFAULT_WEIGHT_DIGITS;
    }

    private function totalLength(): int
    {
        return strlen($this->prefix()) + self::PRODUCT_ID_DIGITS + $this->weightDigits() + 1;
    }

    public function isWeightedBarcode(string $code): bool
    {
        $prefix = $this->prefix();

        return strlen($code) === $this->totalLength()
            && ctype_digit($code)
            && str_starts_with($code, $prefix);
    }

    public function validate(string $code): bool
    {
        if (! $this->isWeightedBarcode($code)) {
            return false;
        }

        $data = substr($code, 0, -1);
        $check = (int) substr($code, -1);

        return $check === Ean13::checkDigit($data);
    }

    /**
     * @return array{product_id:int, weight_kg:float}|null
     */
    public function parse(string $code): ?array
    {
        if (! $this->validate($code)) {
            return null;
        }

        $prefixLen = strlen($this->prefix());
        $productId = (int) substr($code, $prefixLen, self::PRODUCT_ID_DIGITS);
        $grams     = (int) substr($code, $prefixLen + self::PRODUCT_ID_DIGITS, $this->weightDigits());

        return [
            'product_id' => $productId,
            'weight_kg'  => $grams / 1000,
        ];
    }

    public function generate(Product $product, float $weightKg): string
    {
        $prefix       = $this->prefix();
        $weightDigits = $this->weightDigits();
        $maxProductId = (10 ** self::PRODUCT_ID_DIGITS) - 1;
        $maxGrams     = (10 ** $weightDigits) - 1;

        if ($product->id > $maxProductId) {
            throw new \InvalidArgumentException("Товар не поддерживает весовой штрихкод — ID {$product->id} превышает лимит формата ({$maxProductId})");
        }

        $grams = (int) round($weightKg * 1000);

        if ($grams < 1 || $grams > $maxGrams) {
            throw new \InvalidArgumentException("Вес вне допустимого диапазона формата штрихкода (0 - {$maxGrams} г)");
        }

        $data = $prefix
            . str_pad((string) $product->id, self::PRODUCT_ID_DIGITS, '0', STR_PAD_LEFT)
            . str_pad((string) $grams, $weightDigits, '0', STR_PAD_LEFT);

        return $data . Ean13::checkDigit($data);
    }
}
