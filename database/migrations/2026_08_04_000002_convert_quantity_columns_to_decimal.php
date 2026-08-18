<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ConvertQuantityColumnsToDecimal extends Migration
{
    private array $columns = [
        'products'       => ['stock_quantity'],
        'order_items'    => ['quantity', 'refunded_quantity'],
        'cart_items'     => ['quantity'],
        'purchase_items' => ['quantity'],
    ];

    public function up()
    {
        // ALTER COLUMN ... TYPE — синтаксис Postgres. sqlite (тесты) использует
        // динамическую типизацию: столбец, объявленный как integer, всё равно
        // хранит дробные значения без ошибки (type affinity не enforced), так что
        // для целей тестов достаточно оставить колонку как есть.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric(12,3) USING {$column}::numeric(12,3)");
            }
        }
    }

    public function down()
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE integer USING {$column}::integer");
            }
        }
    }
}
