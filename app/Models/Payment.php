<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    // QR-платежи GoPay (OrderQrPaymentService): проводит только банк —
    // вебхук или ответ провайдера (PaymentSettlementService), не сотрудник
    public const BANK_METHODS = ['mbank', 'qr'];

    public function isBankPayment(): bool
    {
        return in_array($this->payment_method, self::BANK_METHODS, true);
    }

    protected $fillable = [
        'order_id',
        'customer_debt_id',
        'payment_method',
        'status',
        'amount',
        'currency',
        'transaction_id',
        'payment_details',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // QR-погашение долга (не оплата самого заказа)
    public function debt()
    {
        return $this->belongsTo(CustomerDebt::class, 'customer_debt_id');
    }
}

