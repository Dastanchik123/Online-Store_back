<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    
    protected function getOrCreateCart()
    {
        $user = Auth::user();
        
        if ($user) {
            return Cart::firstOrCreate(['user_id' => $user->id]);
        }

        $sessionId = session()->getId();
        return Cart::firstOrCreate(['session_id' => $sessionId]);
    }

    
    public function index()
    {
        $cart = $this->getOrCreateCart();
        $cart->load('items.product');
        
        return response()->json([
            'cart' => $cart,
            'total' => $cart->total,
            'items_count' => $cart->items_count,
        ]);
    }

    
    public function addItem(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        if (!$product->in_stock || $product->stock_quantity < $validated['quantity']) {
            return response()->json(['message' => 'Product is out of stock'], 400);
        }

        $cart = $this->getOrCreateCart();
        $price = $product->final_price;

        $cartItem = CartItem::updateOrCreate(
            [
                'cart_id' => $cart->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => $validated['quantity'],
                'price' => $price,
            ]
        );

        $cart->load('items.product');

        return response()->json([
            'message' => 'Item added to cart',
            'cart' => $cart,
            'total' => $cart->total,
        ], 201);
    }

    
    public function updateItem(Request $request, $itemId)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = CartItem::findOrFail($itemId);
        $cart = $this->getOrCreateCart();

        if ($cartItem->cart_id !== $cart->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $product = $cartItem->product;
        if ($product->stock_quantity < $validated['quantity']) {
            return response()->json(['message' => 'Insufficient stock'], 400);
        }

        $cartItem->update(['quantity' => $validated['quantity']]);
        $cart->load('items.product');

        return response()->json([
            'message' => 'Cart item updated',
            'cart' => $cart,
            'total' => $cart->total,
        ]);
    }

    
    public function removeItem($itemId)
    {
        $cartItem = CartItem::findOrFail($itemId);
        $cart = $this->getOrCreateCart();

        if ($cartItem->cart_id !== $cart->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cartItem->delete();
        $cart->load('items.product');

        return response()->json([
            'message' => 'Item removed from cart',
            'cart' => $cart,
            'total' => $cart->total,
        ]);
    }

    
    public function clear()
    {
        $cart = $this->getOrCreateCart();
        $cart->items()->delete();

        return response()->json(['message' => 'Cart cleared']);
    }
}

