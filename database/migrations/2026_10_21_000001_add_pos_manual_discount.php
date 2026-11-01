<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Ручная скидка кассира: права pos.discount (в пределах лимита
// pos_max_discount_percent) и pos.discount_unlimited (без лимита).
// orders.discount остаётся общей скидкой чека (купон + ручная),
// manual_discount — её ручная часть, чтобы в отчётах было видно, кто и
// сколько скинул сам, а сколько дал купон.
return new class extends Migration
{
    private array $permissions = [
        ['role' => 'cashier', 'permission' => 'pos.discount'],
    ];

    public function up()
    {
        if (! Schema::hasColumn('orders', 'manual_discount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('manual_discount', 12, 2)->default(0)->after('discount');
            });
        }

        foreach ($this->permissions as $permission) {
            DB::table('role_permissions')->updateOrInsert(
                $permission,
                array_merge($permission, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        if (! DB::table('settings')->where('key', 'pos_max_discount_percent')->exists()) {
            DB::table('settings')->insert([
                'key'        => 'pos_max_discount_percent',
                'value'      => '10',
                'type'       => 'number',
                'group'      => 'pos',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('role_permissions')->whereIn('permission', ['pos.discount', 'pos.discount_unlimited'])->delete();
        DB::table('settings')->where('key', 'pos_max_discount_percent')->delete();

        if (Schema::hasColumn('orders', 'manual_discount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('manual_discount');
            });
        }
    }
};
