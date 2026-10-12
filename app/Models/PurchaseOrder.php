<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'document_number',
        'status',
        'notes',
        'created_by',
        'ordered_at',
        'completed_at',
        'business_id',
    ];

    protected $casts = [
        'ordered_at' => 'datetime',
        'completed_at' => 'datetime',
        'business_id' => 'integer',
    ];

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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceivings()
    {
        return $this->hasMany(GoodsReceiving::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payable()
    {
        return $this->hasOne(Payable::class);
    }
}
