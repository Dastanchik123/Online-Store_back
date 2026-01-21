<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPurchasePriceToOrderItemsTable extends Migration
{
    
    public function up()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('purchase_price', 15, 2)->nullable()->after('price');
        });
    }

    
    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('purchase_price');
        });
    }
}
