<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUuidToAllTables extends Migration
{
    public function up()
    {
        $tables = [
            'users',
            'categories',
            'products',
            'orders',
            'order_items',
            'financial_transactions'
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (!Schema::hasColumn($table->getTable(), 'uuid')) {
                        $table->uuid('uuid')->nullable()->unique()->after('id');
                    }
                });
            }
        }
    }

    public function down()
    {
        $tables = [
            'users',
            'categories',
            'products',
            'orders',
            'order_items',
            'financial_transactions'
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('uuid');
                });
            }
        }
    }
}
