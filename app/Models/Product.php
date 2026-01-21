<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'sku',
        'purchase_price',
        'price',
        'sale_price',
        'stock_quantity',
        'in_stock',
        'is_active',
        'image',
        'images',
        'category_id',
        'weight',
        'dimensions',
        'attributes',
        'views_count',
        'sales_count',
        'is_rentable',
        'rental_price_per_day',
        'security_deposit',
        'is_hot',
        'hot_order',
        'hot_group',
    ];

    protected $casts = [
        'purchase_price'       => 'decimal:2',
        'price'                => 'decimal:2',
        'sale_price'           => 'decimal:2',
        'in_stock'             => 'boolean',
        'is_active'            => 'boolean',
        'is_rentable'          => 'boolean',
        'rental_price_per_day' => 'decimal:2',
        'security_deposit'     => 'decimal:2',
        'images'               => 'array',
        'attributes'           => 'array',
        'weight'               => 'decimal:2',
        'is_hot'               => 'boolean',
        'hot_order'            => 'integer',
        'hot_group'            => 'string',
    ];

    protected $appends = ['image_url', 'images_urls'];

    
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    
    public function getFinalPriceAttribute()
    {
        return $this->sale_price ?? $this->price;
    }

    public function getDiscountPercentageAttribute()
    {
        if ($this->sale_price && $this->price > $this->sale_price) {
            return round((($this->price - $this->sale_price) / $this->price) * 100);
        }
        return 0;
    }

    public function getAverageRatingAttribute()
    {
        return $this->reviews()->where('is_approved', true)->avg('rating') ?? 0;
    }

    public function getImageUrlAttribute()
    {
        return $this->image ? asset('storage/' . $this->image) : null;
    }

    public function getImagesUrlsAttribute()
    {
        if (empty($this->images)) {
            return [];
        }
        return array_map(function ($path) {
            return asset('storage/' . $path);
        }, $this->images);
    }
}
