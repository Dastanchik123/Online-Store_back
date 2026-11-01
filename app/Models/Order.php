<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

use App\Traits\HasUuid;

class Order extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    // Канал продажи (колонка channel)
    public const CHANNEL_ONLINE       = 'online';
    public const CHANNEL_POS          = 'pos';
    public const CHANNEL_SELF_SERVICE = 'self_service';
    public const CHANNELS             = [self::CHANNEL_ONLINE, self::CHANNEL_POS, self::CHANNEL_SELF_SERVICE];

    protected $fillable = [
        'uuid',
        'order_number',
        'user_id',
        'shipping_address_id',
        'billing_address_id',
        'status',
        'payment_status',
        'payment_method',
        'channel',
        'terminal_id',
        'subtotal',
        'total_amount',
        'tax',
        'shipping_cost',
        'discount',
        'manual_discount',
        'coupon_id',
        'total',
        'currency',
        'notes',
        'is_financed',
        'cash_received',
        'transfer_received',
        'change_amount',
        'is_debt',
        'debt_due_date',
        'staff_id',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'subtotal'      => 'decimal:2',
        'tax'           => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount'        => 'decimal:2',
        'manual_discount' => 'decimal:2',
        'total'           => 'decimal:2',
        'is_debt'         => 'boolean',
        'debt_due_date'   => 'date:Y-m-d',
        'shipped_at'    => 'datetime',
        'delivered_at'  => 'datetime',
    ];

    
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-' . strtoupper(Str::random(10));
            }
            if (empty($order->channel)) {
                $order->channel = self::CHANNEL_ONLINE;
            }
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if (is_numeric($value)) {
            return $this->where('id', $value)->first();
        }
        return $this->where('uuid', $value)->first();
    }

    
    public function user()
    {
        // withTrashed: исторический заказ не должен терять ссылку на клиента,
        // если тот был впоследствии (soft-)удалён — как уже сделано для OrderItem::product().
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id')->withTrashed();
    }

    public function shippingAddress()
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function billingAddress()
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Оплаты самого заказа. QR-погашения долга по заказу сюда не входят: они
    // не должны влиять на статус оплаты заказа, отмену его QR и т.п.
    public function payments()
    {
        return $this->hasMany(Payment::class)->whereNull('customer_debt_id');
    }

    // Способы оплаты, которые ставит только банк (QR GoPay), не сотрудник
    public const BANK_PAYMENT_METHODS = ['mbank', 'qr', 'mixed'];

    // Оплату подтвердил банк (QR GoPay): деньги пришли на счёт, поэтому
    // статус и способ оплаты вручную не меняются — иначе учёт разойдётся с
    // выпиской. Отмена/возврат по-прежнему возможны (деньги — наличными).
    public function isPaidByBank(): bool
    {
        return self::whereBankConfirmed($this->payments())->exists();
    }

    // Подтверждённый банком QR-платёж (ручной платёж сотрудника — не банк)
    public static function whereBankConfirmed($paymentsQuery)
    {
        return $paymentsQuery->where('status', 'completed')->whereIn('payment_method', Payment::BANK_METHODS);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Пересчитывает payment_status по агрегату ВСЕХ платежей заказа (не по
     * одному последнему изменённому) — иначе откат одного платежа на
     * pending/failed мог оставить заказ формально "paid".
     */
    public function syncPaymentStatus(): void
    {
        $this->refresh();

        $completedTotal = (float) $this->payments()->where('status', 'completed')->sum('amount');
        $hasRefunded     = $this->payments()->where('status', 'refunded')->exists();
        $hasFailedOnly   = ! $hasRefunded && $completedTotal <= 0 && $this->payments()->where('status', 'failed')->exists();

        // Сначала «оплачен»: возврат лишнего (двойного) QR-платежа помечает
        // этот платёж refunded, но заказ при этом остаётся оплаченным
        if ($completedTotal > 0 && $completedTotal >= (float) $this->total) {
            $status = 'paid';
        } elseif ($hasRefunded) {
            $status = 'refunded';
        } elseif ($hasFailedOnly) {
            $status = 'failed';
        } else {
            $status = 'pending';
        }

        if ($this->payment_status !== $status) {
            $this->update(['payment_status' => $status]);
        }
    }
}
