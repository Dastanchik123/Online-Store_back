<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DebtPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_debt_id',
        'amount',
        'payment_method',
    ];

    public function debt()
    {
        return $this->belongsTo(CustomerDebt::class, 'customer_debt_id');
    }
}
