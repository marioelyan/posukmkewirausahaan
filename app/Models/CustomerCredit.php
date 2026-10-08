<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerCredit extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'sales_return_id',
        'amount',
        'balance',
        'notes',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'sales_return_id' => 'integer',
        'amount' => 'integer',
        'balance' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }
}
