<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Централизованная точка записи аудита — используется и хуками моделей
     * (AppServiceProvider), и контроллерами, которым нужно зафиксировать
     * действие, не выражающееся изменением полей самой модели (confirm/cancel).
     */
    public static function record(string $action, $model, $old, $new): void
    {
        try {
            static::create([
                'user_id'        => auth()->id(),
                'action'         => $action,
                'auditable_type' => get_class($model),
                'auditable_id'   => $model->id,
                'old_values'     => $old,
                'new_values'     => $new,
                'ip'             => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            // Таблица audit_logs могла ещё не смигрироваться (свежий install) — не роняем основную операцию
        }
    }
}
