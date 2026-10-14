<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiving extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'supplier_id',
        'warehouse_id',
        'document_number',
        'notes',
        'received_by',
        'received_at',
        'business_id',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'business_id' => 'integer',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(GoodsReceivingItem::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
