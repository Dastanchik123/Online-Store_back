<?php

use Illuminate\Database\Migrations\Migration;

class AddUserIdToFinancialTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Redundant - column and foreign key already added in 2026_01_03_195941_create_accounting_system_tables.php
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
