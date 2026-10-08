<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyPointHistory extends Model
{
    use HasFactory;

    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_VOUCHER = 'voucher';

    protected $fillable = [
        'customer_id',
        'transaction_id',
        'type',
        'points_delta',
        'balance_after',
        'amount_delta',
        'reference',
        'notes',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'transaction_id' => 'integer',
        'points_delta' => 'integer',
        'balance_after' => 'integer',
        'amount_delta' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
