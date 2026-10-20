<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

class QuoteRequest extends Model
{
    protected $table = 'marketplace_quote_requests';
    protected $guarded = [];
    protected $casts = ['budget' => 'integer', 'seller_quote_amount' => 'integer', 'preferred_date' => 'date', 'responded_at' => 'datetime', 'accepted_at' => 'datetime'];
}
