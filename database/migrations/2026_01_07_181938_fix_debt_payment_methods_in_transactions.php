<?php

use Illuminate\Database\Migrations\Migration;

class FixDebtPaymentMethodsInTransactions extends Migration
{
    
    public function up()
    {
        $transactions = \App\Models\FinancialTransaction::where('trackable_type', \App\Models\DebtPayment::class)->get();
        foreach ($transactions as $transaction) {
            if ($transaction->trackable) {
                
                
                $transaction->payment_method = $transaction->trackable->payment_method ?? 'cash';
                $transaction->save();
            }
        }
    }

    
    public function down()
    {
        
    }
}
