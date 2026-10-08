<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceivingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receiving_id',
        'purchase_order_item_id',
        'product_id',
        'qty_received',
        'notes',
    ];

    protected $casts = [
        'qty_received' => 'integer',
    ];

    public function goodsReceiving()
    {
        return $this->belongsTo(GoodsReceiving::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
