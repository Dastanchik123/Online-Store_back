<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\HasUuid;
class FinancialTransaction extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'amount',
        'payment_method',
        'category',
        'trackable_type',
        'trackable_id',
        'description',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trackable()
    {
        return $this->morphTo();
    }
}
