<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class CategoryController extends Controller
{

    public function index(Request $request)
    {
        // Кэш ответа (сбрасывается при изменении категорий — ApiCache::bump)
        $payload = \App\Support\ApiCache::remember(
            'categories',
            $request->getQueryString() ?? '',
            600,
            function () use ($request) {
                $query = Category::query();

                if ($request->has('parent_id')) {
                    $query->where('parent_id', $request->parent_id);
                }

                if ($request->has('is_active')) {
                    $query->where('is_active', $request->boolean('is_active'));
                }

                // Категории всегда отдаются в алфавитном порядке
                $query->orderByRaw('LOWER(name) asc');

                return $query->with('parent', 'children')->get()->toArray();
            }
        );

        return response()->json($payload);
    }

    public function store(StoreCategoryRequest $request)
    {
        $validated = $request->validated();

        if (empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']);
            $slug     = $baseSlug;
            $counter  = 1;
            while (Category::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validated['slug'] = $slug;
        }

        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = 'image_' . time() . '_' . Str::random(6) . '.webp';
            $path     = 'categories/' . $filename;

            $imageData = Image::make($file)->fit(500, 500)->encode('webp', 90);
            Storage::disk('public')->put($path, (string) $imageData);

            $validated['image'] = $path;
        }

        $category = Category::create($validated);

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        $category->load('parent', 'children', 'products');
        return response()->json($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $validated = $request->validated();

        if (isset($validated['name']) && empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']);
            $slug     = $baseSlug;
            $counter  = 1;
            while (Category::where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validated['slug'] = $slug;
        }

        if ($request->hasFile('image')) {
            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }

            $file     = $request->file('image');
            $filename = 'image_' . time() . '_' . Str::random(6) . '.webp';
            $path     = 'categories/' . $filename;

            $imageData = Image::make($file)->fit(500, 500)->encode('webp', 90);
            Storage::disk('public')->put($path, (string) $imageData);

            $validated['image'] = $path;
        }

        $category->update($validated);

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        if ($category->image) {
            Storage::disk('public')->delete($category->image);
        }

        $category->delete();
        return response()->json(['message' => 'Category deleted successfully'], 200);
    }
}
