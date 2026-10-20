<?php

namespace App\Models\Marketplace;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shop extends Model
{
    use SoftDeletes;

    protected $table = 'marketplace_shops';

    protected $fillable = [
        'owner_id','public_id','title','slug','description','business_type',
        'custom_business_type','mode','phone','whatsapp','email','city','country',
        'address','logo_url','cover_url',
        'service_fixer_enabled',
        'hiring_enabled',
        'registration_number',
        'website_url',
        'profile_type','delivery_fee','status','rejection_reason',
        'reviewed_by','submitted_at','verified_at',
    ];

    protected $casts = [
        'delivery_fee' => 'integer',
        'hiring_enabled' => 'boolean',
        'service_fixer_enabled' => 'boolean',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function listings() { return $this->hasMany(Listing::class, 'shop_id'); }
}
