<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftCashMovement extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    /**
     * Kinds for cash-out movements. Only `beban` creates an Expense row.
     */
    public const KIND_OUT = [
        'beban',
        'setoran',
        'talangan',
        'pelunasan_hutang',
        'lainnya',
    ];

    /**
     * Kinds for cash-in movements. None creates an Expense row.
     */
    public const KIND_IN = [
        'modal',
        'setoran_masuk',
        'talangan_masuk',
        'lainnya',
    ];

    protected $fillable = ['cashier_shift_id', 'type', 'kind', 'expense_id', 'amount', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
