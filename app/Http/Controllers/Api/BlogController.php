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

        return $query->latest()->paginate($request->get('per_page', 10));
    }

    public function show($slug)
    {
        $post = Post::with('author:id,name')->where('slug', $slug)->firstOrFail();
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
            'image'        => 'nullable|image|max:2048',
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
            'image'        => 'nullable|image|max:2048',
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
