<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $table = 'marketplace_stock_reservations';
    protected $guarded = [];
    protected $casts = ['quantity' => 'integer', 'expires_at' => 'datetime', 'released_at' => 'datetime', 'consumed_at' => 'datetime'];
}
