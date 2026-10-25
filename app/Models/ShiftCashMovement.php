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

    /**
     * Fase 1 slice 2 (spec §3.8, keputusan Q5): destination of a setoran
     * (cash-out) movement. Bank needs bank_account_id (journal Dr 1110);
     * owner journals Dr 3110 Prive. Null = legacy row, left unjournaled.
     */
    public const SETORAN_DESTINATION_BANK = 'bank';

    public const SETORAN_DESTINATION_OWNER = 'owner';

    public const SETORAN_DESTINATIONS = [
        self::SETORAN_DESTINATION_BANK,
        self::SETORAN_DESTINATION_OWNER,
    ];

    protected $fillable = ['cashier_shift_id', 'type', 'kind', 'setoran_destination', 'bank_account_id', 'expense_id', 'payable_id', 'amount', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'bank_account_id' => 'integer',
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

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
