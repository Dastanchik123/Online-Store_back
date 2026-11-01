<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->search;
            // ilike — регистронезависимый поиск на Postgres («иван» находит «Иван»)
            $like = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                    ->orWhere('email', $like, "%{$search}%")
                    ->orWhere('phone', $like, "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        return response()->json($query->latest()->paginate(min(max((int) $request->get('per_page', 15), 1), 200)));
    }

    
    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        // Покупатель без пароля входит по SMS — пароль случайный, его никто не знает
        $validated['password'] = \Illuminate\Support\Facades\Hash::make($validated['password'] ?? \Illuminate\Support\Str::random(40));

        $user = User::create($validated);

        return response()->json($user, 201);
    }

    
    public function show(User $user)
    {
        return response()->json($user);
    }

    
    public function history(Request $request, User $user)
    {
        
        $orders = $user->orders()->latest()->get()->map(function ($order) {
            return [
                'type'        => 'order',
                'id'          => $order->id,
                'amount'      => $order->total,
                'status'      => $order->status,
                'date'        => $order->created_at,
                'description' => "Заказ #{$order->id} ({$order->items_count} товаров)",
            ];
        });

        
        
        $payments = collect();
        $debts    = \App\Models\CustomerDebt::where('user_id', $user->id)->with('payments')->get();

        foreach ($debts as $debt) {
            foreach ($debt->payments as $payment) {
                $payments->push([
                    'type'        => 'payment',
                    'id'          => $payment->id,
                    'amount'      => $payment->amount,
                    'status'      => 'paid',
                    'date'        => $payment->created_at,
                    'description' => "Оплата долга по заказу #{$debt->order_id}",
                ]);
            }
        }

        
        $history = $orders->merge($payments)->sortByDesc('date')->values();

        
        $perPage   = 15;
        $page      = $request->get('page', 1);
        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $history->forPage($page, $perPage)->values(), 
            $history->count(),                            
            $perPage,                                     
            $page,                                        
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginated);
    }

    
    public function update(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();

        if (isset($validated['role']) && $user->role === 'admin' && $validated['role'] !== 'admin') {
            $adminCount = User::where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json(['message' => 'Нельзя понизить последнего администратора.'], 422);
            }
        }

        $passwordChanged = false;
        if (isset($validated['password']) && $validated['password']) {
            $validated['password'] = \Illuminate\Support\Facades\Hash::make($validated['password']);
            $passwordChanged       = true;
        } else {
            unset($validated['password']);
        }

        foreach (['staff_pin' => 'staff_pin_hash', 'badge_code' => 'badge_code_hash'] as $field => $column) {
            if (! $request->exists($field)) {
                continue;
            }
            unset($validated[$field]);

            $value = trim((string) $request->input($field));
            if ($value === '') {
                $validated[$column] = null;
                continue;
            }

            $hash  = User::staffCredentialHash($value);
            $taken = User::where($column, $hash)->where('id', '!=', $user->id)->exists();
            if ($taken) {
                $message = $field === 'staff_pin'
                    ? 'Этот PIN уже занят другим сотрудником — выберите другой.'
                    : 'Этот бейдж уже привязан к другому сотруднику.';
                return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
            }
            $validated[$column] = $hash;
        }

        if ($request->hasFile('avatar')) {
            
            if ($user->avatar_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($user->avatar_path)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            }

            $path                     = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar_path'] = $path;
        }

        $user->update($validated);

        // Новый пароль (например, старый узнали посторонние) — закрываем все
        // сессии этого пользователя: токены живут 30 дней и иначе продолжали
        // бы работать. Админу, меняющему пароль себе, оставляем текущую
        if ($passwordChanged) {
            $currentTokenId = $request->user()->currentAccessToken()->id ?? null;
            $user->tokens()
                ->when($request->user()->id === $user->id && $currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
                ->delete();
        }

        return response()->json($user);
    }

    
    public function destroy(User $user)
    {
        if ($user->role === 'admin' && User::where('role', 'admin')->count() <= 1) {
            return response()->json(['message' => 'Нельзя удалить последнего администратора.'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
}
