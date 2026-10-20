<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use SoftDeletes;

    protected $table = 'marketplace_listings';

    protected $fillable = [
        'public_id','shop_id','kind','title','description','category','custom_category',
        'images','pricing_mode','price','compare_at_price','currency','unit_label',
        'stock_mode','stock_quantity','reserved_quantity','available','status',
        'rejection_reason','reviewed_by','submitted_at','verified_at',
    ];

    protected $casts = [
        'images' => 'array',
        'price' => 'integer',
        'compare_at_price' => 'integer',
        'stock_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'available' => 'boolean',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function shop() { return $this->belongsTo(Shop::class, 'shop_id'); }

    public function availableQuantity(): ?int
    {
        if ($this->stock_mode !== 'tracked') return null;
        return max(0, (int) $this->stock_quantity - (int) $this->reserved_quantity);
    }
}
