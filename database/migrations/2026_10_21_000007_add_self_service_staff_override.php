<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Касса самообслуживания: убрать товар или уменьшить количество покупатель
// может только с подтверждением сотрудника — PIN-кодом или бейджем.
//   users.staff_pin_hash / badge_code_hash — HMAC от значения (см.
//     User::staffCredentialHash), а не bcrypt: по ним ищем сотрудника,
//     поэтому хэш должен быть детерминированным и уникальным
//   self_service_staff_overrides — журнал: кто, на какой кассе и что разрешил
//   право self_service.override — по умолчанию у ролей с доступом к кассе
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_pin_hash', 64)->nullable()->unique();
            $table->string('badge_code_hash', 64)->nullable()->unique();
        });

        Schema::create('self_service_staff_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('terminal_id', 50);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('action', 20);
            $table->json('cart_before')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $posRoles = DB::table('role_permissions')
            ->whereIn('permission', ['pos.access', 'cashier.access'])
            ->where('role', '!=', 'admin')
            ->distinct()
            ->pluck('role');

        foreach ($posRoles as $role) {
            DB::table('role_permissions')->updateOrInsert(
                ['role' => $role, 'permission' => 'self_service.override'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down()
    {
        DB::table('role_permissions')->where('permission', 'self_service.override')->delete();

        Schema::dropIfExists('self_service_staff_overrides');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['staff_pin_hash']);
            $table->dropUnique(['badge_code_hash']);
            $table->dropColumn(['staff_pin_hash', 'badge_code_hash']);
        });
    }
};
