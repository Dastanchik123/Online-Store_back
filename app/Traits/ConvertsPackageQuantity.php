<?php
namespace App\Traits;

use App\Models\Product;

trait ConvertsPackageQuantity
{
    /**
     * Переводит количество в базовые единицы товара, если оно указано в упаковках
     * (рулон/мешок/т.п.) — используется и при закупке, и при возврате поставщику,
     * чтобы оба места одинаково домножали на product.package_size.
     */
    private function baseQuantity(?Product $product, float $quantity, bool $isPackage): float
    {
        if ($isPackage && $product && $product->package_size) {
            return $quantity * (float) $product->package_size;
        }
        return $quantity;
    }
}
