<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SelfServiceDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SelfServiceDeviceController extends Controller
{
    // ─────────────────────────── Админка ───────────────────────────

    public function index()
    {
        return response()->json(SelfServiceDevice::latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'terminal_id' => 'required|string|max:50',
            'label'       => 'nullable|string|max:100',
        ]);

        $device = SelfServiceDevice::create($validated);

        return response()->json($device, 201);
    }

    // Новый код пейринга можно выпустить и повторно — например, если касса
    // потеряла привязку (переустановка) или старый код никто не ввёл за 10 минут.
    public function issuePairingCode(SelfServiceDevice $device)
    {
        $code = (string) random_int(100000, 999999);

        $device->update([
            'pairing_code'             => $code,
            'pairing_code_expires_at'  => Carbon::now()->addMinutes(10),
        ]);

        return response()->json([
            'pairing_code' => $code,
            'expires_at'   => $device->pairing_code_expires_at,
        ]);
    }

    public function revoke(SelfServiceDevice $device)
    {
        $device->update([
            'revoked_at'  => Carbon::now(),
            'token_hash'  => null,
            'paired_at'   => null,
        ]);

        return response()->json(['status' => 'revoked']);
    }

    // ─────────────────────────── Касса ───────────────────────────

    // Публичный эндпоинт (self-service ещё не имеет токена, только код
    // пейринга) — вызывается один раз при настройке киоска, не на каждой
    // продаже. Rate-limit на роуте защищает от перебора 6-значного кода.
    public function pair(Request $request)
    {
        $validated = $request->validate([
            'pairing_code' => 'required|string',
        ]);

        $device = SelfServiceDevice::where('pairing_code', $validated['pairing_code'])
            ->whereNull('revoked_at')
            ->first();

        if (! $device || ! $device->pairing_code_expires_at || $device->pairing_code_expires_at->isPast()) {
            return response()->json(['message' => 'Код недействителен или истёк.'], 422);
        }

        $rawToken = Str::random(64);

        $device->update([
            'token_hash'               => hash('sha256', $rawToken),
            'pairing_code'             => null,
            'pairing_code_expires_at'  => null,
            'paired_at'                => Carbon::now(),
            'last_seen_at'             => Carbon::now(),
        ]);

        return response()->json([
            'device_token' => $rawToken,
            'terminal_id'  => $device->terminal_id,
        ]);
    }
}
