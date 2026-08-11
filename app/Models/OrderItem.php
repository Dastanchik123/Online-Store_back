<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\HasUuid;
class OrderItem extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'uuid',
        'order_id',
        'product_id',
        'product_name',
        'product_sku',
        'quantity',
        'is_package',
        'refunded_quantity',
        'purchase_price',
        'price',
        'total',
    ];

    protected $casts = [
        'quantity'          => 'decimal:3',
        'refunded_quantity' => 'decimal:3',
        'is_package'        => 'boolean',
        'purchase_price'    => 'decimal:2',
        'price'             => 'decimal:2',
        'total'             => 'decimal:2',
    ];

    
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        // withTrashed: восстановление/списание остатков при отмене и
        // возврате заказа должно находить товар, даже если он с тех пор
        // был soft-deleted — иначе $item->product вернёт null.
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
