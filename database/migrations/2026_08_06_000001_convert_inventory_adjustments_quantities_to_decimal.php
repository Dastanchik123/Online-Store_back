<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ConvertInventoryAdjustmentsQuantitiesToDecimal extends Migration
{
    private array $columns = ['old_quantity', 'new_quantity', 'difference'];

    public function up()
    {
        // ALTER COLUMN ... TYPE — синтаксис Postgres, недоступен на sqlite (тесты).
        // sqlite использует динамическую типизацию и без проблем хранит дробные
        // значения в integer-колонке, поэтому для тестов достаточно no-op.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $column) {
            DB::statement("ALTER TABLE inventory_adjustments ALTER COLUMN {$column} TYPE numeric(12,3) USING {$column}::numeric(12,3)");
        }
    }

    public function down()
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $column) {
            DB::statement("ALTER TABLE inventory_adjustments ALTER COLUMN {$column} TYPE integer USING {$column}::integer");
        }
    }
}
