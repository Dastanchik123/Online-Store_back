<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    public function index(Request $request)
    {
        $query = Post::with('author:id,name');

        if (! $request->user() || $request->user()->role === 'user') {
            $query->where('is_published', true);
        }

        return $query->latest()->paginate(min(max((int) $request->get('per_page', 10), 1), 100));
    }

    public function show($slug)
    {
        // Черновик по slug открывается только персоналу. Роут публичный,
        // поэтому пользователя берём из sanctum-гарда явно.
        $viewer = auth('sanctum')->user();
        $post   = Post::with('author:id,name')
            ->where('slug', $slug)
            ->when(! $viewer || $viewer->role === 'user', fn ($q) => $q->where('is_published', true))
            ->firstOrFail();
        return response()->json($post);
    }

    public function adminShow($id)
    {
        $post = Post::findOrFail($id);
        return response()->json($post);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'content'      => 'required|string',
            'is_published' => 'boolean',
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:20480',
        ]);

        $validated['slug']      = Str::slug($validated['title']) . '-' . rand(1000, 9999);
        $validated['author_id'] = $request->user()->id;

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('blog', 'public');
        }

        $post = Post::create($validated);
        return response()->json($post, 201);
    }

    public function update(Request $request, Post $post)
    {
        $validated = $request->validate([
            'title'        => 'sometimes|required|string|max:255',
            'content'      => 'sometimes|required|string',
            'is_published' => 'boolean',
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:20480',
        ]);

        if (isset($validated['title']) && $validated['title'] !== $post->title) {
            $validated['slug'] = Str::slug($validated['title']) . '-' . rand(1000, 9999);
        }

        if ($request->hasFile('image')) {
            if ($post->image_path) {
                Storage::disk('public')->delete($post->image_path);
            }
            $validated['image_path'] = $request->file('image')->store('blog', 'public');
        }

        $post->update($validated);
        return response()->json($post);
    }

    public function destroy(Post $post)
    {
        if ($post->image_path) {
            Storage::disk('public')->delete($post->image_path);
        }
        $post->delete();
        return response()->json(['message' => 'Post deleted']);
    }
}
