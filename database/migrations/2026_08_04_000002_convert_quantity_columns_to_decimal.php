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
        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric(12,3) USING {$column}::numeric(12,3)");
            }
        }
    }

    public function down()
    {
        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE integer USING {$column}::integer");
            }
        }
    }
}
