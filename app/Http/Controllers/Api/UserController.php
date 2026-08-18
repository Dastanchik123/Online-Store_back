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
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        return response()->json($query->latest()->paginate($request->get('per_page', 15)));
    }

    
    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        $validated['password'] = \Illuminate\Support\Facades\Hash::make($validated['password']);

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

        if (isset($validated['password']) && $validated['password']) {
            $validated['password'] = \Illuminate\Support\Facades\Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            
            if ($user->avatar_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($user->avatar_path)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            }

            $path                     = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar_path'] = $path;
        }

        $user->update($validated);

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
