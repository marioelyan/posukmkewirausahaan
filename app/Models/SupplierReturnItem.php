<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_return_id',
        'goods_receiving_item_id',
        'product_id',
        'qty_returned',
        'unit_price',
        'reason',
        'notes',
    ];

    protected $casts = [
        'qty_returned' => 'integer',
        'unit_price' => 'float',
    ];

    public function supplierReturn()
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function goodsReceivingItem()
    {
        return $this->belongsTo(GoodsReceivingItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
