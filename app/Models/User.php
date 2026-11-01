<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\HasUuid;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuid, SoftDeletes;

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
        'phone_verified_at',
        'avatar_path',
        'terminal_id',
        'staff_pin_hash',
        'badge_code_hash',
    ];

    
    protected $hidden = [
        'password',
        'remember_token',
        'staff_pin_hash',
        'badge_code_hash',
    ];

    
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
    ];

    // Номер всегда в одном виде (+996XXXXXXXXX) — по нему вход через SMS и
    // поиск клиента на кассе. Не номер КР сохраняется как ввели.
    public function setPhoneAttribute($value)
    {
        $value = is_string($value) ? trim($value) : $value;

        $this->attributes['phone'] = $value === null || $value === ''
            ? null
            : (\App\Support\Phone::normalize($value) ?? $value);
    }

    protected $appends = ['avatar_url', 'has_staff_pin', 'has_badge'];

    // PIN/бейдж сотрудника для подтверждений на кассе самообслуживания.
    // HMAC с ключом приложения: детерминированный (сотрудника ищем по хэшу),
    // но без APP_KEY короткий PIN из дампа БД перебором не восстановить.
    public static function staffCredentialHash(string $value): string
    {
        return hash_hmac('sha256', trim($value), (string) config('app.key'));
    }

    public function getHasStaffPinAttribute(): bool
    {
        return $this->staff_pin_hash !== null;
    }

    public function getHasBadgeAttribute(): bool
    {
        return $this->badge_code_hash !== null;
    }

    
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


    public function hasPermission(string $permission): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        return RolePermission::where('role', $this->role)
            ->where('permission', $permission)
            ->exists();
    }
}
