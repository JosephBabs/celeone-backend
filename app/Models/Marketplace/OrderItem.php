<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'marketplace_order_items';
    protected $guarded = [];
    protected $casts = ['image_urls' => 'array', 'quantity' => 'integer', 'unit_price' => 'integer', 'line_total' => 'integer'];
    public function order() { return $this->belongsTo(Order::class, 'order_id'); }
}
