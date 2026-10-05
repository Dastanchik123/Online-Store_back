<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        // withTrashed: товар мог быть soft-deleted после добавления в избранное —
        // без этого $wishlist->product молча становится null (фронт на это не
        // рассчитан и падает, см. аудит удаления).
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
