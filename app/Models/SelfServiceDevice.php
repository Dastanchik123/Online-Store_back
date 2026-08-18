<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SelfServiceDevice extends Model
{
    protected $fillable = [
        'terminal_id',
        'label',
        'token_hash',
        'pairing_code',
        'pairing_code_expires_at',
        'paired_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
        'pairing_code',
    ];

    protected $casts = [
        'pairing_code_expires_at' => 'datetime',
        'paired_at'                => 'datetime',
        'last_seen_at'             => 'datetime',
        'revoked_at'                => 'datetime',
    ];

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
