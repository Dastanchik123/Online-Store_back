<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerDebt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'order_id',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'due_date',
        'status',
    ];

    public function user()
    {
        // withTrashed: user_id обязателен (NOT NULL) — долг всегда привязан к
        // реальному клиенту, "гостевых" долгов не бывает. Без withTrashed
        // soft-deleted клиент выглядел бы неотличимо от гостя (см. аудит удаления).
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payments()
    {
        return $this->hasMany(DebtPayment::class);
    }
}
