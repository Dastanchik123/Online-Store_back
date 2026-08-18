<?php

use Illuminate\Database\Migrations\Migration;

class FixDebtPaymentMethodsInTransactions extends Migration
{
    
    public function up()
    {
        // Используем Eloquent-модель напрямую нельзя: на момент выполнения этой
        // миграции (при migrate:fresh с нуля, напр. в тестах) колонка deleted_at
        // ещё не существует, а модель FinancialTransaction использует SoftDeletes,
        // из-за чего глобальный scope добавляет WHERE deleted_at IS NULL и запрос
        // падает ("no such column"). На боевой БД это не проявлялось, т.к. там
        // миграции применялись последовательно по мере разработки. Обходим через
        // query builder, который не знает о глобальных scope модели.
        $transactions = \Illuminate\Support\Facades\DB::table('financial_transactions')
            ->where('trackable_type', \App\Models\DebtPayment::class)
            ->get();

        foreach ($transactions as $transaction) {
            $debtPayment = \Illuminate\Support\Facades\DB::table('debt_payments')
                ->where('id', $transaction->trackable_id)
                ->first();

            if ($debtPayment) {
                \Illuminate\Support\Facades\DB::table('financial_transactions')
                    ->where('id', $transaction->id)
                    ->update(['payment_method' => $debtPayment->payment_method ?? 'cash']);
            }
        }
    }

    
    public function down()
    {
        
    }
}
