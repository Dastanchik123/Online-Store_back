<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PhoneLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    
    // Вход покупателя по номеру: шаг 1 — SMS с кодом
    public function sendPhoneCode(Request $request, PhoneLoginService $phoneLogin)
    {
        $request->validate(['phone' => 'required|string|max:32']);

        return response()->json($phoneLogin->sendCode($request->phone, $request->ip()));
    }

    // Шаг 2 — код из SMS. needs_name: новый покупатель, спросить имя
    public function verifyPhoneCode(Request $request, PhoneLoginService $phoneLogin)
    {
        $request->validate([
            'phone' => 'required|string|max:32',
            'code'  => 'required|string|max:8',
        ]);

        $result = $phoneLogin->verify($request->phone, $request->code);

        return response()->json([
            'user'       => $result['user'],
            'token'      => $result['token'],
            'token_type' => 'Bearer',
            'needs_name' => $result['needs_name'],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Successfully logged out']);
    }

    
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Email необязателен: покупатель может о нём не знать. Номер, по
        // которому он входит, меняется только через магазин
        $validated = $request->validate([
            'name'   => 'required|string|max:255',
            'email'  => 'sometimes|nullable|email|max:255|unique:users,email,' . $user->id,
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            }
            $validated['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
            unset($validated['avatar']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Профиль обновлен',
            'user'    => $user,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        
        

        
        return response()->json(['message' => 'Если такой email существует, мы отправили на него ссылку (SMTP не настроен, это заглушка)']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
            'token'    => 'required',
        ]);

        
        

        return response()->json(['message' => 'Функция сброса требует настройки почтового сервера. Пожалуйста, используйте админ-панель для смены пароля.'], 400);
    }
    public function myDebts(Request $request)
    {
        $debts = $request->user()->debts()
            ->where('status', '!=', 'paid') 
            ->with(['payments', 'order.items.product'])
            ->latest()
            ->get();

        return response()->json($debts);
    }
}
