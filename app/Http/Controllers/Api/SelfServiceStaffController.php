<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Подтверждение сотрудника на кассе самообслуживания: покупатель не может сам
// убрать товар или уменьшить количество (иначе «отсканировал → удалил →
// унёс»), касса просит PIN или бейдж сотрудника с правом self_service.override.
// Каждое подтверждение пишется в журнал вместе с корзиной до изменения.
class SelfServiceStaffController extends Controller
{
    public function authorizeOverride(Request $request)
    {
        $validated = $request->validate([
            'method'                  => 'required|in:pin,badge',
            'credential'              => 'required|string|max:64',
            'action'                  => 'required|in:remove,decrement,clear',
            'cart'                    => 'nullable|array|max:200',
            'cart.*.product_id'       => 'required|integer',
            'cart.*.quantity'         => 'required|numeric|min:0',
        ]);

        $column = $validated['method'] === 'pin' ? 'staff_pin_hash' : 'badge_code_hash';
        $staff  = User::where($column, User::staffCredentialHash($validated['credential']))->first();

        if (! $staff) {
            $message = $validated['method'] === 'pin' ? 'Неверный PIN-код.' : 'Бейдж не распознан.';
            return response()->json(['message' => $message], 422);
        }

        if (! $staff->hasPermission('self_service.override')) {
            return response()->json(['message' => 'У сотрудника нет права подтверждать изменения на кассе.'], 403);
        }

        DB::table('self_service_staff_overrides')->insert([
            'terminal_id' => $request->attributes->get('self_service_terminal_id'),
            'user_id'     => $staff->id,
            'method'      => $validated['method'],
            'action'      => $validated['action'],
            'cart_before' => isset($validated['cart']) ? json_encode($validated['cart']) : null,
            'created_at'  => now(),
        ]);

        return response()->json(['staff' => ['name' => $staff->name]]);
    }
}
