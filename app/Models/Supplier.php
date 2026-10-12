<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'business_id',
    ];

    public function payables()
    {
        return $this->hasMany(Payable::class);
    }
}
