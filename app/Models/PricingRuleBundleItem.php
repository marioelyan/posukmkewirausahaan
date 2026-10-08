<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingRuleBundleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'pricing_rule_id',
        'product_id',
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
