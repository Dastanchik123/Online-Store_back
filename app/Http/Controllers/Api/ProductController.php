<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query()->with('category');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('in_stock')) {
            $query->where('in_stock', $request->boolean('in_stock'));
        }

        if ($request->has('is_hot')) {
            $query->where('is_hot', $request->boolean('is_hot'));
        }

        if ($request->has('hot_group')) {
            $query->where('hot_group', $request->hot_group);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        $orderBy  = 'name';
        $orderDir = 'asc';

        if ($request->boolean('is_hot')) {
            $orderBy  = 'hot_order';
            $orderDir = 'asc';
        }

        $perPage = $request->get('per_page', 15);

        if ($perPage == -1) {
            $products = $query->orderBy($orderBy, $orderDir)->get();
            return response()->json([
                'data'         => $products,
                'total'        => $products->count(),
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => $products->count(),
            ]);
        }

        $products = $query->orderBy($orderBy, $orderDir)->paginate($perPage);

        return response()->json($products);
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'slug'              => 'nullable|string|max:255|unique:products',
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string',
            'sku'               => 'required|string|max:255|unique:products',
            'purchase_price'    => 'nullable|numeric|min:0',
            'price'             => 'required|numeric|min:0',
            'sale_price'        => 'nullable|numeric|min:0',
            'stock_quantity'    => 'integer|min:0',
            'in_stock'          => 'boolean',
            'is_active'         => 'boolean',
            'is_hot'            => 'boolean',

            'image'             => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',

            'images'            => 'nullable|array',
            'images.*'          => 'image|mimes:jpeg,png,jpg,webp|max:10240',

            'category_id'       => 'required|exists:categories,id',
            'weight'            => 'nullable|numeric|min:0',
            'dimensions'        => 'nullable|string',
            'attributes'        => 'nullable|array',
            'hot_order'         => 'nullable|integer',
            'hot_group'         => 'nullable|string|max:50',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = 'image_' . time() . '_' . Str::random(6) . '.webp';
            $path     = 'products/' . $filename;

            $image = Image::make($file)->encode('webp', 90);
            Storage::disk('public')->put($path, (string) $image);

            $validated['image'] = $path;
        }

        if ($request->hasFile('images')) {
            $galleryPaths = [];
            foreach ($request->file('images') as $key => $file) {
                $filename = 'image_' . time() . '_' . Str::random(6) . '_' . ($key + 1) . '.webp';
                $path     = 'products/gallery/' . $filename;

                $image = Image::make($file)->encode('webp', 90);
                Storage::disk('public')->put($path, (string) $image);

                $galleryPaths[] = $path;
            }
            $validated['images'] = $galleryPaths;
        }

        $product = Product::create($validated);
        return response()->json($product->load('category'), 201);
    }

    public function show(Product $product)
    {
        $product->increment('views_count');
        $product->load('category', 'reviews.user');
        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name'              => 'sometimes|required|string|max:255',
            'slug'              => 'sometimes|nullable|string|max:255|unique:products,slug,' . $product->id,
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string',
            'sku'               => 'sometimes|required|string|max:255|unique:products,sku,' . $product->id,
            'purchase_price'    => 'nullable|numeric|min:0',
            'price'             => 'sometimes|required|numeric|min:0',
            'sale_price'        => 'nullable|numeric|min:0',
            'in_stock'          => 'boolean',
            'is_active'         => 'boolean',
            'is_hot'            => 'boolean',

            'image'             => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
            'images'            => 'nullable|array',
            'images.*'          => 'image|mimes:jpeg,png,jpg,webp|max:10240',

            'category_id'       => 'sometimes|required|exists:categories,id',
            'weight'            => 'nullable|numeric|min:0',
            'dimensions'        => 'nullable|string',
            'attributes'        => 'nullable|array',
            'hot_order'         => 'nullable|integer',
            'hot_group'         => 'nullable|string|max:50',
        ]);

        if (isset($validated['name']) && empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = 'image_' . time() . '_' . Str::random(6) . '.webp';
            $path     = 'products/' . $filename;

            $image = Image::make($file)->encode('webp', 90);
            Storage::disk('public')->put($path, (string) $image);

            $validated['image'] = $path;
        }

        if ($request->hasFile('images')) {
            $galleryPaths = [];
            foreach ($request->file('images') as $key => $file) {
                $filename = 'image_' . time() . '_' . Str::random(6) . '_' . ($key + 1) . '.webp';
                $path     = 'products/gallery/' . $filename;

                $image = Image::make($file)->encode('webp', 90);
                Storage::disk('public')->put($path, (string) $image);

                $galleryPaths[] = $path;
            }
            $validated['images'] = $galleryPaths;
        }

        $product->update($validated);

        return response()->json($product->load('category'));
    }

    public function destroy(Product $product)
    {
        try {

            if ($product->orderItems()->exists()) {
                return response()->json([
                    'message' => 'Нельзя удалить товар, по которому уже были заказы. Рекомендуется архивировать товар (сделать неактивным).',
                ], 409);
            }

            $product->cartItems()->delete();
            $product->reviews()->delete();

            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            if (! empty($product->images)) {
                foreach ($product->images as $path) {
                    Storage::disk('public')->delete($path);
                }
            }

            $product->delete();
            return response()->json(['message' => 'Товар успешно удален'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при удалении товара',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function generateSku()
    {
        $unique = false;
        $sku    = '';

        while (! $unique) {

            $sku = date('ymd') . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

            if (! Product::where('sku', $sku)->exists()) {
                $unique = true;
            }
        }

        return response()->json(['sku' => $sku]);
    }
}
