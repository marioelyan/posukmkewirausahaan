<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockOpname extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'code',
        'warehouse_id',
        'status',
        'notes',
        'created_by',
        'finalized_by',
        'finalized_at',
        'business_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'created_by' => 'integer',
        'finalized_by' => 'integer',
        'finalized_at' => 'datetime',
        'business_id' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
