<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSoftDeletesToDebtPaymentsTable extends Migration
{
    public function up()
    {
        Schema::table('debt_payments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::table('debt_payments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
