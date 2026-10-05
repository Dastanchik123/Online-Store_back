<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddSupplierReturnsPermissions extends Migration
{
    private array $permissions = [
        ['role' => 'purchaser', 'permission' => 'supplier_returns.manage'],
        ['role' => 'purchaser', 'permission' => 'supplier_returns.delete'],
    ];

    public function up()
    {
        foreach ($this->permissions as $permission) {
            DB::table('role_permissions')->updateOrInsert(
                $permission,
                array_merge($permission, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down()
    {
        foreach ($this->permissions as $permission) {
            DB::table('role_permissions')->where($permission)->delete();
        }
    }
}
