<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ConvertInventoryAdjustmentsQuantitiesToDecimal extends Migration
{
    private array $columns = ['old_quantity', 'new_quantity', 'difference'];

    public function up()
    {
        foreach ($this->columns as $column) {
            DB::statement("ALTER TABLE inventory_adjustments ALTER COLUMN {$column} TYPE numeric(12,3) USING {$column}::numeric(12,3)");
        }
    }

    public function down()
    {
        foreach ($this->columns as $column) {
            DB::statement("ALTER TABLE inventory_adjustments ALTER COLUMN {$column} TYPE integer USING {$column}::integer");
        }
    }
}
