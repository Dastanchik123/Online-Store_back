<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImageEmbedding extends Model
{
    protected $fillable = ['product_id', 'image_path', 'model', 'embedding'];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
