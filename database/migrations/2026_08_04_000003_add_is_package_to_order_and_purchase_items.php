<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsPackageToOrderAndPurchaseItems extends Migration
{
    public function up()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_package')->default(false)->after('quantity');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->boolean('is_package')->default(false)->after('quantity');
        });
    }

    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('is_package');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('is_package');
        });
    }
}
