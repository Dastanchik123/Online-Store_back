<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\HasUuid;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuid;

    public function resolveRouteBinding($value, $field = null)
    {
        if (is_numeric($value)) {
            return $this->where('id', $value)->first();
        }
        return $this->where('uuid', $value)->first();
    }

    
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'role',
        'phone',
        'avatar_path',
        'terminal_id',
    ];

    
    protected $hidden = [
        'password',
        'remember_token',
    ];

    
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected $appends = ['avatar_url'];

    
    public function getAvatarUrlAttribute()
    {
        return $this->avatar_path ? asset('storage/' . $this->avatar_path) : null;
    }

    
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function debts()
    {
        return $this->hasMany(CustomerDebt::class);
    }

    public function setTerminalIdAttribute($value)
    {
        if (!$value) {
            $this->attributes['terminal_id'] = null;
            return;
        }

        // Если пришло число (например "1"), добавляем префикс "k"
        if (is_numeric($value)) {
            $this->attributes['terminal_id'] = 'k' . $value;
        } else {
            $this->attributes['terminal_id'] = $value;
        }
    }

    public function wishlist()
    {
        return $this->hasMany(Wishlist::class);
    }
}
