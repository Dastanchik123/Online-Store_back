<?php
namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['name' => 'admin', 'label' => 'Администратор', 'is_system' => true],
            ['name' => 'user', 'label' => 'Пользователь', 'is_system' => true],
            ['name' => 'manager', 'label' => 'Менеджер', 'is_system' => true],
            ['name' => 'cashier', 'label' => 'Кассир', 'is_system' => true],
            ['name' => 'purchaser', 'label' => 'Закупщик', 'is_system' => true],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
