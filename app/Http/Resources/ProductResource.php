<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'description'       => $this->description,
            'short_description' => $this->short_description,
            'sku'               => $this->sku,
            'price'             => $this->price,
            'sale_price'        => $this->sale_price,
            'stock_quantity'    => $this->stock_quantity,
            'in_stock'          => $this->in_stock,
            'is_active'         => $this->is_active,
            'image'             => $this->image,
            'images'            => $this->images,
            'image_url'         => $this->image ? asset('storage/' . $this->image) : null,
            'images_urls'       => $this->images ? array_map(function ($image) {
                                        return asset('storage/' . $image);
                                    }, $this->images) : [],
            'weight'            => $this->weight,
            'dimensions'        => $this->dimensions,
            'attributes'        => $this->attributes,

            
            
            
            
            
            
            
            
            
            
            
            
            'category'          => $this->category['name'],
            'views_count'       => $this->views_count,
            'sales_count'       => $this->sales_count,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
