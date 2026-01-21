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
        $query = Review::query()->with('user', 'product');

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('is_approved')) {
            $query->where('is_approved', $request->boolean('is_approved'));
        }

        $perPage = $request->get('per_page', 15);
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
        $review->load('user', 'product', 'order');
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
        $review->update(['is_approved' => true]);
        return response()->json(['message' => 'Review approved', 'review' => $review]);
    }
}

