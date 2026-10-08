<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingRuleQtyBreak extends Model
{
    use HasFactory;

    protected $fillable = [
        'pricing_rule_id',
        'min_qty',
        'discount_type',
        'discount_value',
        'sort_order',
    ];

    protected $casts = [
        'pricing_rule_id' => 'integer',
        'min_qty' => 'integer',
        'discount_value' => 'float',
        'sort_order' => 'integer',
    ];

    public function pricingRule()
    {
        return $this->belongsTo(PricingRule::class);
    }
}
