<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsStaff
{
    
    public function handle(Request $request, Closure $next): Response
    {
        // 'staff' — это "любая роль, кроме обычного покупателя ('user')".
        // Роли теперь динамические (таблица roles), поэтому здесь нельзя
        // хардкодить конкретные имена — иначе новая кастомная роль (например
        // "Старший кассир") никогда не пройдёт дальше этого middleware, даже
        // если ей выданы нужные permissions. Реальная гранулярность доступа
        // обеспечивается уже внутри — middleware('permission:...') и
        // $user->hasPermission() на уровне конкретных роутов/контроллеров.
        if ($request->user() && $request->user()->role !== 'user') {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden. Staff access required.'], 403);
    }
}
