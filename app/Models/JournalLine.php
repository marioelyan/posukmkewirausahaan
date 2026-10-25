<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a journal entry (spec §4).
 *
 * Exactly one of debit / credit is filled per line; the other stays 0.
 * Enforced by JournalService (see its resolveLines), not by a DB CHECK,
 * so the migration stays portable across MySQL and SQLite.
 */
class JournalLine extends Model
{
    protected $fillable = [
        'entry_id',
        'account_id',
        'debit',
        'credit',
        'note',
    ];

    protected $casts = [
        'entry_id' => 'integer',
        'account_id' => 'integer',
        'debit' => 'integer',
        'credit' => 'integer',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
