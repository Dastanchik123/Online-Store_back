<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddChannelAndTerminalIdToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // channel различает источник заказа (админ/касса кассира/сайт/касса
            // самообслуживания) для отчётности; nullable — старые заказы им не
            // размечены и это ок, они и так узнаваемы по staff_id/notes.
            $table->string('channel')->nullable()->after('payment_method');
            // Для self-service заказов нет staff_id (гость без логина) — именно
            // этим полем помечается касса-терминал, выбившая заказ.
            $table->string('terminal_id')->nullable()->after('channel');
            $table->index('channel');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn(['channel', 'terminal_id']);
        });
    }
}
