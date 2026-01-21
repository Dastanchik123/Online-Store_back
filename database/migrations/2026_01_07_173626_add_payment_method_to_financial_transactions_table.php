<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPaymentMethodToFinancialTransactionsTable extends Migration
{
    
    public function up()
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->string('payment_method')->default('cash')->after('amount'); 
        });
    }

    
    public function down()
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
}
