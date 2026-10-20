<?php

namespace App\Models\Marketplace;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'marketplace_orders';

    protected $fillable = [
        'public_id','buyer_id','shop_id','status','subtotal','delivery_fee',
        'platform_fee','seller_net','total','currency','delivery_method',
        'delivery_address','buyer_phone','payment_status','provider',
        'provider_transaction_id','paid_at','seller_completed_at',
        'buyer_received_at','admin_verified_at','completed_at','verified_by',
    ];

    protected $casts = [
        'subtotal' => 'integer','delivery_fee' => 'integer','platform_fee' => 'integer',
        'seller_net' => 'integer','total' => 'integer',
        'paid_at' => 'datetime','seller_completed_at' => 'datetime',
        'buyer_received_at' => 'datetime','admin_verified_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function buyer() { return $this->belongsTo(User::class, 'buyer_id'); }
    public function shop() { return $this->belongsTo(Shop::class, 'shop_id'); }
    public function items() { return $this->hasMany(OrderItem::class, 'order_id'); }
    public function payments() { return $this->hasMany(Payment::class, 'order_id'); }
}
