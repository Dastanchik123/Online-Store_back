<?php
namespace App\Console\Commands;

use App\Models\FinancialTransaction;
use App\Models\Purchase;
use Illuminate\Console\Command;

class FixPurchaseExpenses extends Command
{
    protected $signature   = 'fix:expenses';
    protected $description = 'Fixes inconsistencies in purchase expenses';

    public function handle()
    {
        $purchases = Purchase::all();
        foreach ($purchases as $p) {
            if (! $p->paid_amount) {
                continue;
            }

            $topUps = FinancialTransaction::where('trackable_type', Purchase::class)
                ->where('trackable_id', $p->id)
                ->where('category', '!=', 'purchase')
                ->sum('amount');

            $main = FinancialTransaction::where('trackable_type', Purchase::class)
                ->where('trackable_id', $p->id)
                ->where('category', 'purchase')
                ->first();

            $mainAmount = $main ? $main->amount : 0;

            $currentTotal = $mainAmount + $topUps;
            $diff         = abs($currentTotal - $p->paid_amount);

            if ($diff > 1) {
                $this->info("Mismatch Purchase #{$p->id}: DB_Total={$p->paid_amount} vs Trans_Sum={$currentTotal} (Main={$mainAmount}, TopUps={$topUps})");

                
                $correctMainAmount = $p->paid_amount - $topUps;

                if ($correctMainAmount < 0) {
                    $this->error("Cannot fix Purchase #{$p->id} automatically: TopUps ($topUps) exceed Total Paid ({$p->paid_amount})");
                    continue;
                }

                if ($main) {
                    $main->amount = $correctMainAmount;
                    $main->save();
                    $this->info(" -> Updated main transaction to $correctMainAmount");
                } else {
                    if ($correctMainAmount > 0) {
                        FinancialTransaction::create([
                            'type'           => 'expense',
                            'amount'         => $correctMainAmount,
                            'category'       => 'purchase',
                            'trackable_type' => Purchase::class,
                            'trackable_id'   => $p->id,
                            'description'    => "Оплата за покупку №{$p->id}",
                        ]);
                        $this->info(" -> Created missing main transaction with $correctMainAmount");
                    }
                }
            }
        }
        $this->info('Done.');
    }
}
