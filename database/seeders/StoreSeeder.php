<?php
namespace Database\Seeders;

use App\Models\RolePermission;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run()
    {

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
}
