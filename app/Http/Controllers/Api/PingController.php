<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

// Проверка связи с сервером для кассы (баннер «Нет связи с сервером»):
// без БД и авторизации, чтобы отвечать даже когда база просыпается
class PingController extends Controller
{
    public function __invoke()
    {
        return response()->json(['ok' => true]);
    }
}
