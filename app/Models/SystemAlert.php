<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'message',
        'data',
        'is_read',
        'priority',
    ];

    protected $casts = [
        'data'    => 'array',
        'is_read' => 'boolean',
    ];
}
