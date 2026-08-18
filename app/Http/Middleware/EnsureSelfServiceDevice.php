<?php
namespace App\Http\Middleware;

use App\Models\SelfServiceDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

// Гейтит эндпоинты, которые дёргает сам киоск (создание заказа, статус,
// отмена, чек). /self-service/pair и /self-service/pay/{token}* сюда не
// входят: pair — момент, когда токена ещё нет; pay/* открывается на
// телефоне покупателя, а не на кассе.
class EnsureSelfServiceDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Касса не авторизована.'], 401);
        }

        $device = SelfServiceDevice::where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Касса не авторизована.'], 401);
        }

        $device->update(['last_seen_at' => Carbon::now()]);

        // Терминал теперь всегда берётся из записи устройства, а не из тела
        // запроса — иначе спуфинг terminal_id остаётся возможен даже с валидным
        // токеном другого терминала.
        $request->attributes->set('self_service_terminal_id', $device->terminal_id);

        return $next($request);
    }
}
