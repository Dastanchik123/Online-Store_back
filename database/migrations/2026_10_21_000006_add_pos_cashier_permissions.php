<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Возможности кассира стали правами роли (страница «Права доступа»):
//   pos.change_price        — вместо общей настройки pos_allow_price_change
//   pos.sell_negative_stock — продажа больше, чем есть по учёту
//   pos.manual_weight       — весовой товар без штрихкода весов
// Чтобы после обновления ничего не сломалось, выдаём их ролям кассы так,
// как касса работала до этого: минус и ручной вес были разрешены всем,
// смена цены — если была включена настройка.
return new class extends Migration
{
    public function up()
    {
        $posRoles = DB::table('role_permissions')
            ->whereIn('permission', ['pos.access', 'cashier.access'])
            ->where('role', '!=', 'admin')
            ->distinct()
            ->pluck('role');

        $priceChangeWasAllowed = in_array(
            DB::table('settings')->where('key', 'pos_allow_price_change')->value('value'),
            ['1', 'true'],
            true
        );

        $permissions = ['pos.sell_negative_stock', 'pos.manual_weight'];
        if ($priceChangeWasAllowed) {
            $permissions[] = 'pos.change_price';
        }

        foreach ($posRoles as $role) {
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role' => $role, 'permission' => $permission],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down()
    {
        DB::table('role_permissions')
            ->whereIn('permission', ['pos.change_price', 'pos.sell_negative_stock', 'pos.manual_weight'])
            ->delete();
    }
};
