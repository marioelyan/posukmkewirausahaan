<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayablePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payable_id',
        'paid_at',
        'amount',
        'method',
        'bank_account_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount' => 'float',
    ];

    public function payable()
    {
        return $this->belongsTo(Payable::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
