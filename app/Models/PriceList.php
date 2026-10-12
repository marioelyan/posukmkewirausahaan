<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['name', 'slug', 'outlet_id', 'business_id', 'customer_scope', 'customer_segment_id', 'is_active', 'priority', 'notes'];

    protected function casts(): array
    {
        return ['business_id' => 'integer', 'outlet_id' => 'integer', 'is_active' => 'boolean', 'priority' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function segment()
    {
        return $this->belongsTo(CustomerSegment::class);
    }

    public function scopeActive($q)
    {
        $q->where('is_active', true);
    }
}
