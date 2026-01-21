<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsFinancedToOrdersTable extends Migration
{
    
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_financed')->default(false)->after('payment_status');
            $table->decimal('cash_received', 15, 2)->default(0)->after('is_financed');
            $table->decimal('transfer_received', 15, 2)->default(0)->after('cash_received');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_financed');
        });
    }
}
