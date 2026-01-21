<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role'     => 'required|string|in:admin,user,purchaser,cashier',
            'phone'    => 'nullable|string',
        ]);

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

    
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|email|unique:users,email,' . $user->id,
            'role'     => 'sometimes|string|in:admin,user,purchaser,cashier',
            'phone'    => 'nullable|string',
            'password' => 'nullable|string|min:8',
            'avatar'   => 'nullable|image|max:10240', 
        ]);

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
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
}
