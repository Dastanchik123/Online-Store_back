<?php
namespace Database\Seeders;

use App\Models\RolePermission;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run()
    {
        $labelTemplates = $this->buildLabelTemplates();
        $priceTagTemplate = collect($labelTemplates)->firstWhere('role', 'price_tag');
        $barcodeTemplate = collect($labelTemplates)->firstWhere('role', 'barcode');

        $settings = [
            ['key' => 'site_name', 'value' => 'MyShop', 'group' => 'general'],
            ['key' => 'contact_phone', 'value' => '+996 555 123 456', 'group' => 'contacts'],
            ['key' => 'contact_email', 'value' => 'info@myshop.kg', 'group' => 'contacts'],
            ['key' => 'contact_address', 'value' => 'г. Бишкек, ул. Чуй 114', 'group' => 'contacts'],
            ['key' => 'site_inn', 'value' => '12356789012345', 'group' => 'contacts'],
            ['key' => 'social_instagram', 'value' => 'https://instagram.com/myshop', 'group' => 'social'],
            ['key' => 'social_whatsapp', 'value' => 'https://wa.me/996555123456', 'group' => 'social'],
            ['key' => 'currency_symbol', 'value' => 'сом', 'group' => 'general'],
            ['key' => 'free_shipping_threshold', 'value' => '5000', 'group' => 'general'],
            ['key' => 'pos_allow_debt', 'value' => '1', 'group' => 'pos'],
            ['key' => 'pos_allow_price_change', 'value' => '1', 'group' => 'pos'],
            ['key' => 'receipt_header', 'value' => 'Добро пожаловать!', 'group' => 'receipt'],
            ['key' => 'receipt_title', 'value' => 'ФИРМЕННЫЙ МАГАЗИН', 'group' => 'receipt'],
            ['key' => 'receipt_phone', 'value' => '+996 555 123 456', 'group' => 'receipt'],
            ['key' => 'receipt_footer', 'value' => 'Спасибо за покупку!', 'group' => 'receipt'],
            ['key' => 'label_templates_all', 'value' => json_encode($labelTemplates, JSON_UNESCAPED_UNICODE), 'group' => 'labels'],
            ['key' => 'label_active_template_price_tag', 'value' => json_encode($priceTagTemplate, JSON_UNESCAPED_UNICODE), 'group' => 'labels'],
            ['key' => 'label_active_template_barcode', 'value' => json_encode($barcodeTemplate, JSON_UNESCAPED_UNICODE), 'group' => 'labels'],
        ];

        foreach ($settings as $s) {
            Setting::updateOrCreate(['key' => $s['key']], $s);
        }

        $permissions = [

            ['role' => 'manager', 'permission' => 'orders.view'],
            ['role' => 'manager', 'permission' => 'orders.edit'],
            ['role' => 'manager', 'permission' => 'products.view'],
            ['role' => 'manager', 'permission' => 'products.edit'],
            ['role' => 'manager', 'permission' => 'customers.view'],

            ['role' => 'admin', 'permission' => 'reports.view'],
            ['role' => 'admin', 'permission' => 'settings.edit'],
            ['role' => 'admin', 'permission' => 'coupons.manage'],
            ['role' => 'admin', 'permission' => 'inventory.manage'],
        ];

        foreach ($permissions as $p) {
            RolePermission::updateOrCreate($p);
        }
    }

    /**
     * Шаблоны для встроенного редактора этикеток (label-editor.html).
     * Формат элементов совпадает со схемой, которую генерирует сам редактор
     * (createElement() в label-editor.html), чтобы шаблоны можно было
     * открыть и доредактировать там же после сидирования.
     */
    private function buildLabelTemplates(): array
    {
        return [
            $this->labelTemplate('tpl_barcode_30x20', '30×20 · Штрихкод', 30, 20, 'none', [
                $this->labelElement('e1', 'price', ['x' => 1, 'y' => 1, 'w' => 15, 'h' => 6], ['currency' => 'сом', 'showKopecks' => false], ['fontSize' => 3.2, 'bold' => true]),
                $this->labelElement('e2', 'barcode', ['x' => 1, 'y' => 7, 'w' => 28, 'h' => 12], ['format' => 'CODE128', 'showDigits' => true]),
            ]),

            $this->labelTemplate('tpl_barcode_43x25', '43×25 · Штрихкод', 43, 25, 'none', [
                $this->labelElement('e1', 'product_name', ['x' => 1, 'y' => 1, 'w' => 41, 'h' => 6], ['field' => 'name'], ['fontSize' => 2.8, 'bold' => true]),
                $this->labelElement('e2', 'price', ['x' => 1, 'y' => 7, 'w' => 20, 'h' => 7], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 4.2, 'bold' => true]),
                $this->labelElement('e3', 'barcode', ['x' => 1, 'y' => 15, 'w' => 41, 'h' => 9], ['format' => 'CODE128', 'showDigits' => true]),
            ]),

            $this->labelTemplate('tpl_barcode_58x30', '58×30 · Штрихкод', 58, 30, 'barcode', [
                $this->labelElement('e1', 'product_name', ['x' => 2, 'y' => 1, 'w' => 54, 'h' => 7], ['field' => 'name'], ['fontSize' => 3.2, 'bold' => true]),
                $this->labelElement('e2', 'price', ['x' => 2, 'y' => 9, 'w' => 26, 'h' => 8], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 5, 'bold' => true]),
                $this->labelElement('e3', 'article', ['x' => 30, 'y' => 11, 'w' => 26, 'h' => 4], ['prefix' => 'Арт: ', 'field' => 'article'], ['fontSize' => 2.4, 'align' => 'right']),
                $this->labelElement('e4', 'barcode', ['x' => 2, 'y' => 18, 'w' => 54, 'h' => 11], ['format' => 'CODE128', 'showDigits' => true]),
            ]),

            $this->labelTemplate('tpl_barcode_58x40', '58×40 · Штрихкод', 58, 40, 'none', [
                $this->labelElement('e1', 'product_name', ['x' => 2, 'y' => 2, 'w' => 54, 'h' => 10], ['field' => 'name'], ['fontSize' => 3.6, 'bold' => true]),
                $this->labelElement('e2', 'price', ['x' => 2, 'y' => 12, 'w' => 30, 'h' => 11], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 7, 'bold' => true]),
                $this->labelElement('e3', 'old_price', ['x' => 33, 'y' => 14.5, 'w' => 22, 'h' => 6], ['currency' => 'сом'], ['fontSize' => 3.6]),
                $this->labelElement('e4', 'barcode', ['x' => 2, 'y' => 24, 'w' => 54, 'h' => 13], ['format' => 'CODE128', 'showDigits' => true]),
                $this->labelElement('e5', 'store_name', ['x' => 2, 'y' => 0.5, 'w' => 30, 'h' => 3.5], ['useGlobal' => true], ['fontSize' => 2.6]),
                $this->labelElement('e6', 'date', ['x' => 44, 'y' => 0.5, 'w' => 12, 'h' => 3.5], ['mode' => 'auto', 'fixedDate' => ''], ['fontSize' => 2.4, 'align' => 'right']),
            ]),

            $this->labelTemplate('tpl_barcode_58x60', '58×60 · Штрихкод', 58, 60, 'none', [
                $this->labelElement('e1', 'product_name', ['x' => 2, 'y' => 2, 'w' => 54, 'h' => 12], ['field' => 'name'], ['fontSize' => 4, 'bold' => true]),
                $this->labelElement('e2', 'price', ['x' => 2, 'y' => 16, 'w' => 30, 'h' => 13], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 8, 'bold' => true]),
                $this->labelElement('e3', 'old_price', ['x' => 33, 'y' => 19, 'w' => 22, 'h' => 7], ['currency' => 'сом'], ['fontSize' => 4]),
                $this->labelElement('e4', 'article', ['x' => 2, 'y' => 31, 'w' => 54, 'h' => 4], ['prefix' => 'Арт: ', 'field' => 'article'], ['fontSize' => 2.6]),
                $this->labelElement('e5', 'barcode', ['x' => 2, 'y' => 37, 'w' => 54, 'h' => 20], ['format' => 'CODE128', 'showDigits' => true]),
                $this->labelElement('e6', 'store_name', ['x' => 2, 'y' => 0.5, 'w' => 36, 'h' => 4], ['useGlobal' => true], ['fontSize' => 2.8]),
                $this->labelElement('e7', 'date', ['x' => 44, 'y' => 0.5, 'w' => 12, 'h' => 4], ['mode' => 'auto', 'fixedDate' => ''], ['fontSize' => 2.4, 'align' => 'right']),
            ]),

            $this->labelTemplate('tpl_barcode_100x50', '100×50 · Штрихкод', 100, 50, 'none', [
                $this->labelElement('e1', 'product_name', ['x' => 3, 'y' => 3, 'w' => 70, 'h' => 10], ['field' => 'name'], ['fontSize' => 4.2, 'bold' => true]),
                $this->labelElement('e2', 'price', ['x' => 3, 'y' => 15, 'w' => 40, 'h' => 14], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 9, 'bold' => true]),
                $this->labelElement('e3', 'old_price', ['x' => 45, 'y' => 19, 'w' => 28, 'h' => 7], ['currency' => 'сом'], ['fontSize' => 4]),
                $this->labelElement('e4', 'barcode', ['x' => 3, 'y' => 33, 'w' => 94, 'h' => 14], ['format' => 'CODE128', 'showDigits' => true]),
                $this->labelElement('e5', 'store_name', ['x' => 3, 'y' => 1, 'w' => 40, 'h' => 4], ['useGlobal' => true], ['fontSize' => 2.8]),
                $this->labelElement('e6', 'date', ['x' => 80, 'y' => 1, 'w' => 17, 'h' => 4], ['mode' => 'auto', 'fixedDate' => ''], ['fontSize' => 2.6, 'align' => 'right']),
            ]),

            $this->labelTemplate('tpl_barcode_100x150', '100×150 · Штрихкод', 100, 150, 'none', [
                $this->labelElement('e1', 'store_name', ['x' => 4, 'y' => 2, 'w' => 60, 'h' => 5], ['useGlobal' => true], ['fontSize' => 3.4]),
                $this->labelElement('e2', 'date', ['x' => 76, 'y' => 2, 'w' => 20, 'h' => 5], ['mode' => 'auto', 'fixedDate' => ''], ['fontSize' => 3, 'align' => 'right']),
                $this->labelElement('e3', 'product_name', ['x' => 4, 'y' => 8, 'w' => 92, 'h' => 16], ['field' => 'name'], ['fontSize' => 6, 'bold' => true]),
                $this->labelElement('e4', 'price', ['x' => 4, 'y' => 26, 'w' => 50, 'h' => 20], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 12, 'bold' => true]),
                $this->labelElement('e5', 'old_price', ['x' => 58, 'y' => 32, 'w' => 38, 'h' => 10], ['currency' => 'сом'], ['fontSize' => 5.5]),
                $this->labelElement('e6', 'discount', ['x' => 4, 'y' => 48, 'w' => 30, 'h' => 8], ['format' => '-{d}%'], ['fontSize' => 4.5, 'bold' => true, 'color' => '#d21f1f']),
                $this->labelElement('e7', 'article', ['x' => 4, 'y' => 60, 'w' => 92, 'h' => 6], ['prefix' => 'Арт: ', 'field' => 'article'], ['fontSize' => 3.2]),
                $this->labelElement('e8', 'unit', ['x' => 4, 'y' => 68, 'w' => 92, 'h' => 6], ['prefix' => ''], ['fontSize' => 3.2]),
                $this->labelElement('e9', 'country', ['x' => 4, 'y' => 76, 'w' => 92, 'h' => 6], ['prefix' => 'Страна: '], ['fontSize' => 3.2]),
                $this->labelElement('e10', 'barcode', ['x' => 4, 'y' => 86, 'w' => 92, 'h' => 50], ['format' => 'CODE128', 'showDigits' => true]),
            ]),

            $this->labelTemplate('tpl_pricetag_58x40', '58×40 · Ценник', 58, 40, 'price_tag', [
                $this->labelElement('e1', 'product_name', ['x' => 3, 'y' => 3, 'w' => 52, 'h' => 12], ['field' => 'name'], ['fontSize' => 4, 'bold' => true]),
                $this->labelElement('e2', 'price', ['x' => 3, 'y' => 17, 'w' => 32, 'h' => 14], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 8, 'bold' => true]),
                $this->labelElement('e3', 'old_price', ['x' => 36, 'y' => 21, 'w' => 20, 'h' => 7], ['currency' => 'сом'], ['fontSize' => 3.6]),
                $this->labelElement('e4', 'store_name', ['x' => 3, 'y' => 33, 'w' => 32, 'h' => 4], ['useGlobal' => true], ['fontSize' => 2.6]),
                $this->labelElement('e5', 'date', ['x' => 40, 'y' => 33, 'w' => 15, 'h' => 4], ['mode' => 'auto', 'fixedDate' => ''], ['fontSize' => 2.4, 'align' => 'right']),
            ]),

            $this->labelTemplate('tpl_pricetag_100x50', '100×50 · Ценник', 100, 50, 'none', [
                $this->labelElement('e1', 'product_name', ['x' => 4, 'y' => 4, 'w' => 92, 'h' => 14], ['field' => 'name'], ['fontSize' => 5, 'bold' => true]),
                $this->labelElement('e2', 'price', ['x' => 4, 'y' => 20, 'w' => 45, 'h' => 18], ['currency' => 'сом', 'showKopecks' => true], ['fontSize' => 11, 'bold' => true]),
                $this->labelElement('e3', 'old_price', ['x' => 52, 'y' => 26, 'w' => 40, 'h' => 9], ['currency' => 'сом'], ['fontSize' => 5]),
                $this->labelElement('e4', 'discount', ['x' => 4, 'y' => 40, 'w' => 25, 'h' => 8], ['format' => '-{d}%'], ['fontSize' => 4, 'bold' => true, 'color' => '#d21f1f']),
                $this->labelElement('e5', 'store_name', ['x' => 4, 'y' => 2, 'w' => 40, 'h' => 4], ['useGlobal' => true], ['fontSize' => 2.8]),
                $this->labelElement('e6', 'date', ['x' => 80, 'y' => 2, 'w' => 17, 'h' => 4], ['mode' => 'auto', 'fixedDate' => ''], ['fontSize' => 2.6, 'align' => 'right']),
            ]),
        ];
    }

    private function labelTemplate(string $id, string $name, float $width, float $height, string $role, array $elements): array
    {
        return compact('id', 'name', 'width', 'height', 'role', 'elements');
    }

    private function labelElement(string $id, string $type, array $pos, array $props = [], array $style = []): array
    {
        return array_merge([
            'id' => $id,
            'type' => $type,
            'x' => $pos['x'],
            'y' => $pos['y'],
            'w' => $pos['w'],
            'h' => $pos['h'],
            'rot' => 0,
            'fontSize' => $style['fontSize'] ?? 3.2,
            'bold' => $style['bold'] ?? false,
            'align' => $style['align'] ?? 'left',
            'color' => $style['color'] ?? '#000000',
            'props' => $props,
        ]);
    }
}
