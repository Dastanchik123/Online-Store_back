<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    protected static function bootHasUuid()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /*
    public function getRouteKeyName()
    {
        return 'uuid';
    }
    */

    // Маршруты принимают и числовой id, и uuid: старые ссылки по id
    // продолжают работать, а переходы по uuid не роняют Postgres
    // ошибкой "invalid input syntax for type bigint"
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === null && is_string($value) && Str::isUuid($value)) {
            return $this->where('uuid', $value)->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
