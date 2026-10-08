<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingRuleBuyGetItem extends Model
{
    use HasFactory;

    public const ROLE_BUY = 'buy';

    public const ROLE_GET = 'get';

    protected $fillable = [
        'pricing_rule_id',
        'product_id',
        'role',
        'quantity',
        'sort_order',
    ];

    protected $casts = [
        'pricing_rule_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function pricingRule()
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
