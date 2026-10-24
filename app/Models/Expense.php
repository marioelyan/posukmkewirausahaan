<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    public const SOURCE_DRAWER = 'drawer';

    public const SOURCE_BANK = 'bank';

    public const SOURCE_OTHER_CASH = 'other_cash';

    public const SOURCES = [
        self::SOURCE_DRAWER,
        self::SOURCE_BANK,
        self::SOURCE_OTHER_CASH,
    ];

    protected $casts = [
        'id' => 'integer',
        'business_id' => 'integer',
        'amount' => 'integer',
        'date' => 'date',
    ];

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'expense_category_id', 'warehouse_id', 'cashier_shift_id', 'date',
        'amount', 'source', 'note', 'proof_reference', 'user_id', 'business_id',
    ];

    /**
     * category
     *
     * @return void
     */
    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * warehouse
     *
     * @return void
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * shift
     *
     * @return void
     */
    public function shift()
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /**
     * user
     *
     * @return void
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * cashMovement
     *
     * @return void
     */
    public function cashMovement()
    {
        return $this->hasOne(ShiftCashMovement::class);
    }
}
