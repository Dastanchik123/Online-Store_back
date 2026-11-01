<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Вход покупателей по номеру телефона и коду из SMS:
//  - email больше не обязателен;
//  - телефон хранится в одном виде (+996XXXXXXXXX) и уникален — это и логин
//    покупателя, и связь с клиентом кассы (его долгами и покупками);
//  - одноразовые коды входа.
return new class extends Migration
{
    public function up()
    {
        // sqlite (тесты) ALTER COLUMN не умеет — там email nullable сразу в
        // create_users_table
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        }

        if (! Schema::hasColumn('users', 'phone_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            });
        }

        $this->normalizePhones();

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone');
        });

        Schema::create('phone_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 16)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip', 45)->nullable()->index();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('phone_login_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn('phone_verified_at');
        });
    }

    // Номера приводятся к +996XXXXXXXXX. Если один номер записан у нескольких
    // клиентов, он остаётся у того, у кого больше заказов и долгов (при
    // равенстве — у самого раннего), у остальных очищается — они в логе.
    private function normalizePhones(): void
    {
        $users = DB::table('users')->whereNotNull('phone')->where('phone', '!=', '')->orderBy('id')->get(['id', 'phone']);

        $groups = [];
        foreach ($users as $user) {
            $normalized = Phone::normalize($user->phone);
            if ($normalized === null) {
                // Не номер КР (или опечатка) — оставляем как есть, по нему
                // просто нельзя будет войти через SMS
                $groups['raw:' . $user->phone][] = $user->id;
                continue;
            }
            $groups[$normalized][] = $user->id;
        }

        foreach ($groups as $phone => $ids) {
            $raw = str_starts_with($phone, 'raw:');
            $keep = $ids[0];

            if (count($ids) > 1) {
                $keep = collect($ids)->sortByDesc(fn ($id) => [
                    DB::table('orders')->where('user_id', $id)->count()
                    + DB::table('customer_debts')->where('user_id', $id)->count(),
                    -$id,
                ])->first();

                $cleared = array_values(array_diff($ids, [$keep]));
                DB::table('users')->whereIn('id', $cleared)->update(['phone' => null]);
                Log::warning('phone_login migration: повторяющийся номер оставлен у одного клиента', [
                    'phone'   => $raw ? substr($phone, 4) : $phone,
                    'kept'    => $keep,
                    'cleared' => $cleared,
                ]);
            }

            if (! $raw) {
                DB::table('users')->where('id', $keep)->update(['phone' => $phone]);
            }
        }
    }
};
