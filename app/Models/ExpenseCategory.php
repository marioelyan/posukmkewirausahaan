<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'business_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'name', 'account_code', 'is_active', 'business_id',
    ];

    /**
     * expenses
     *
     * @return void
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
