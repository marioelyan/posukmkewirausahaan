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

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    protected static function booted(): void
    {
        // Menghapus supplier melepas status titipan produknya juga, supaya
        // tidak tertinggal produk bertanda konsinyasi tanpa penitip.
        // supplier_id-nya sendiri di-NULL-kan oleh FK nullOnDelete.
        static::deleting(function (Supplier $supplier) {
            $supplier->products()->update(['is_consignment' => false]);
        });
    }
}
