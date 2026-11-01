<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'image_path',
        'is_published',
        'author_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    protected $appends = ['image_url'];

    // Очищается и при записи, и при чтении: старые статьи, сохранённые до
    // появления очистки, тоже не должны отдавать фронту (v-html) скрипты.
    public function setContentAttribute($value)
    {
        $this->attributes['content'] = \App\Support\HtmlSanitizer::clean($value);
    }

    public function getContentAttribute($value)
    {
        return \App\Support\HtmlSanitizer::clean($value);
    }

    public function getImageUrlAttribute()
    {
        if ($this->image_path) {
            return url(\Illuminate\Support\Facades\Storage::url($this->image_path));
        }
        return null;
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
