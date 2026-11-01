<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    
    public function index(Request $request)
    {
        // Роут публичный (без auth:sanctum, см. routes/api.php) — Auth::user()
        // здесь недоступен, поэтому не пытаемся различать "своё"/staff, а просто
        // отдаём только одобренные отзывы по товару (единственный реальный кейс
        // использования — фронт не вызывает ни user_id, ни is_approved).
        // От автора — только имя: полная модель User отдавала бы email/телефон
        // каждого, кто оставил отзыв, любому гостю.
        $query = Review::query()->with('user:id,name', 'product')->where('is_approved', true);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $reviews = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($reviews);
    }

    
    public function store(Request $request)
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'order_id' => 'nullable|exists:orders,id',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'nullable|string',
        ]);

        
        $existingReview = Review::where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($existingReview) {
            return response()->json(['message' => 'You have already reviewed this product'], 400);
        }

        
        if (isset($validated['order_id'])) {
            $order = \App\Models\Order::findOrFail($validated['order_id']);
            if ($order->user_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $validated['user_id'] = $user->id;
        $validated['is_approved'] = false; 

        $review = Review::create($validated);
        $review->load('user', 'product');

        return response()->json($review, 201);
    }

    
    public function show(Review $review)
    {
        // Публичный роут: неодобренные отзывы и заказ автора не показываем
        if (! $review->is_approved) {
            abort(404);
        }

        $review->load('user:id,name', 'product');
        return response()->json($review);
    }

    
    public function update(Request $request, Review $review)
    {
        $user = Auth::user();
        
        if (!$user || $review->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'rating' => 'sometimes|required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'nullable|string',
        ]);

        $review->update($validated);
        $review->load('user', 'product');

        return response()->json($review);
    }

    
    public function destroy(Review $review)
    {
        $user = Auth::user();
        
        if (!$user || $review->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $review->delete();

        return response()->json(['message' => 'Review deleted successfully'], 200);
    }

    
    public function approve(Review $review)
    {
        // Модерация отзывов — часть карточки товара: то же право, что на её
        // редактирование (есть у менеджера/закупщика, нет у кассира)
        if (! Auth::user()->hasPermission('products.edit')) {
            return response()->json(['message' => 'Forbidden. Missing permission: products.edit'], 403);
        }

        $review->update(['is_approved' => true]);
        return response()->json(['message' => 'Review approved', 'review' => $review]);
    }
}

