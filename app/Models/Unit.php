<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['code', 'name', 'symbol', 'business_id'];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_units')
            ->using(ProductUnit::class)
            ->withPivot(['is_base', 'conversion_factor', 'buy_price', 'sell_price', 'barcode', 'sku_suffix']);
    }
}
