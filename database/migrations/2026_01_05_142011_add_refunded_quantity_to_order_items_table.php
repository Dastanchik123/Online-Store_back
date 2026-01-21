<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRefundedQuantityToOrderItemsTable extends Migration
{
    
    public function up()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('refunded_quantity')->default(0)->after('quantity');
        });
    }

    
    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('refunded_quantity');
        });
    }
}
