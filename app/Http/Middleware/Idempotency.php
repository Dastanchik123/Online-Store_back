<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// Защита от двойного выполнения запроса (заголовок Idempotency-Key, как у
// Stripe / черновика IETF httpapi-idempotency-key-header). Типичный случай:
// продажа на кассе прошла, но ответ потерялся по таймауту — кассир жмёт
// «Оплатить» ещё раз с тем же ключом и получает ту же продажу, а не вторую.
//
// Сохраняются только успешные (2xx) ответы: после 4xx/5xx ключ освобождается,
// и кассир может исправить чек и повторить.
class Idempotency
{
    // Запрос с ключом, «зависший» дольше этого (упал PHP-процесс),
    // считается брошенным — его ключ можно занять заново
    private const STALE_SECONDS = 120;

    private const RETENTION_DAYS = 7;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || $request->isMethodSafe()) {
            return $next($request);
        }

        if (strlen($key) > 100) {
            return response()->json(['message' => 'Некорректный Idempotency-Key.'], 422);
        }

        $userId      = optional($request->user())->id;
        $requestHash = hash('sha256', $request->method() . ' ' . $request->path() . ' ' . $request->getContent());

        $this->pruneOccasionally();

        $claimed = $this->claim($key, $userId, $request, $requestHash);
        if ($claimed instanceof Response) {
            return $claimed;
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            DB::table('idempotency_keys')->where('key', $key)->update([
                'status_code' => $response->getStatusCode(),
                'response'    => $response->getContent(),
                'updated_at'  => now(),
            ]);
        } else {
            DB::table('idempotency_keys')->where('key', $key)->delete();
        }

        return $response;
    }

    // true — ключ наш, запрос выполняем; Response — ответить сразу
    private function claim(string $key, ?int $userId, Request $request, string $requestHash)
    {
        $row = [
            'key'          => $key,
            'user_id'      => $userId,
            'method'       => $request->method(),
            'path'         => $request->path(),
            'request_hash' => $requestHash,
            'created_at'   => now(),
            'updated_at'   => now(),
        ];

        if (DB::table('idempotency_keys')->insertOrIgnore($row) === 1) {
            return true;
        }

        $existing = DB::table('idempotency_keys')->where('key', $key)->first();

        // Строку успели удалить между insert и select (параллельный 4xx/5xx)
        if (! $existing) {
            return DB::table('idempotency_keys')->insertOrIgnore($row) === 1
                ? true
                : response()->json(['message' => 'Запрос уже выполняется, подождите.'], 409);
        }

        if ((int) $existing->user_id !== (int) $userId || $existing->request_hash !== $requestHash) {
            return response()->json([
                'message' => 'Этот ключ уже использован для другого запроса.',
            ], 422);
        }

        if ($existing->status_code !== null) {
            return response($existing->response, (int) $existing->status_code)
                ->header('Content-Type', 'application/json')
                ->header('Idempotent-Replayed', 'true');
        }

        // Запрос с этим ключом ещё выполняется — второй не запускаем
        if (now()->diffInSeconds($existing->created_at) < self::STALE_SECONDS) {
            return response()->json(['message' => 'Продажа ещё обрабатывается, подождите несколько секунд.'], 409);
        }

        // Брошенный ключ (процесс упал, не записав результат) — занимаем заново
        $taken = DB::table('idempotency_keys')
            ->where('key', $key)
            ->whereNull('status_code')
            ->where('created_at', $existing->created_at)
            ->update(['created_at' => now(), 'updated_at' => now()]);

        return $taken === 1
            ? true
            : response()->json(['message' => 'Продажа ещё обрабатывается, подождите несколько секунд.'], 409);
    }

    // Отдельной задачи по расписанию нет — чистим старые ключи изредка,
    // попутно с обычными запросами
    private function pruneOccasionally(): void
    {
        if (random_int(1, 100) === 1) {
            DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
        }
    }
}
