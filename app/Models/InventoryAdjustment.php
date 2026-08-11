<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryAdjustment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'old_quantity',
        'new_quantity',
        'difference',
        'reason',
        'user_id',
    ];

    protected $casts = [
        'old_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
        'difference'   => 'decimal:3',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
