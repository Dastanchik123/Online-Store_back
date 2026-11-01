<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'min_order_amount',
        'usage_limit',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'expires_at'       => 'datetime',
        'value'            => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'usage_limit'      => 'integer',
        'used_count'       => 'integer',
    ];

    public function isValid()
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    // Один купон — один раз на покупателя (отменённые заказы не считаются)
    public function wasUsedBy(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        return Order::where('coupon_id', $this->id)
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->exists();
    }
}
