<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    protected $fillable = [
        'uuid', 'type', 'params', 'status', 'file_path', 'file_name',
        'error', 'user_id', 'completed_at',
    ];

    protected $casts = [
        'params'       => 'array',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
