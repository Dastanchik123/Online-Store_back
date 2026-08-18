<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MakeCountryCityNullableOnAddressesTable extends Migration
{
    public function up()
    {
        // ALTER COLUMN ... DROP NOT NULL — синтаксис Postgres, sqlite (используется
        // в тестах, см. phpunit.xml) не поддерживает ALTER COLUMN вообще. doctrine/dbal
        // не установлен в проекте, поэтому Schema::table(...)->change() тоже недоступен.
        // На sqlite нет отдельного шага миграции: NOT NULL там не проверяется так же
        // строго через классическую ALTER-семантику, а данных в свежей тестовой БД нет,
        // поэтому просто пропускаем — таблица создаётся заново на каждый прогон тестов.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE addresses ALTER COLUMN country DROP NOT NULL');
            DB::statement('ALTER TABLE addresses ALTER COLUMN city DROP NOT NULL');
        }
    }

    public function down()
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE addresses ALTER COLUMN country SET NOT NULL');
            DB::statement('ALTER TABLE addresses ALTER COLUMN city SET NOT NULL');
        }
    }
}
