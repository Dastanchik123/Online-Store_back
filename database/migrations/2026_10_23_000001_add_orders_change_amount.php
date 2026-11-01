<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Сдача покупателю на кассе. cash_received хранит наличную часть оплаты уже
// без сдачи (она идёт в кассу/финансы), поэтому для повторной печати чека
// («Наличные» = внесено, «Сдача») сдачу храним отдельно.
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('orders', 'change_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('change_amount', 12, 2)->default(0)->after('transfer_received');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('orders', 'change_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('change_amount');
            });
        }
    }
};
