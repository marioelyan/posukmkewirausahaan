<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerSegmentMembership extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO = 'auto';

    protected $fillable = [
        'customer_id',
        'customer_segment_id',
        'source',
        'matched_at',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'customer_segment_id' => 'integer',
        'matched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Memberships carry no `business_id`; ownership is inherited from the
        // parent customer. With tenancy enabled this keeps every read/aggregate
        // (segment member counts, `memberships` relation, stats) scoped to the
        // active business, and fails closed when there is no context because the
        // Customer global scope already does so.
        static::addGlobalScope('business_via_customer', function (Builder $builder) {
            if (! config('multi_business.enabled')) {
                return;
            }

            $builder->whereIn(
                $builder->getModel()->getTable().'.customer_id',
                Customer::query()->select('customers.id')
            );
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function segment()
    {
        return $this->belongsTo(CustomerSegment::class, 'customer_segment_id');
    }
}
