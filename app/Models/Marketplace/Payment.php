<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'marketplace_payments';
    protected $guarded = [];
    protected $casts = ['amount' => 'integer', 'provider_payload' => 'array', 'confirmed_at' => 'datetime'];
    public function order() { return $this->belongsTo(Order::class, 'order_id'); }
}
