<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A posted journal entry (spec §4).
 *
 * Immutable by design: JournalService exposes no update or delete path for
 * entries. Corrections are made only via reversing entries
 * (JournalService::reverse), which reference the original through
 * reverses_entry_id.
 */
class JournalEntry extends Model
{
    protected $fillable = [
        'business_id',
        'date',
        'source_type',
        'source_id',
        'period',
        'reverses_entry_id',
        'description',
        'created_by',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'source_id' => 'integer',
        'reverses_entry_id' => 'integer',
        'created_by' => 'integer',
        'date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }
}
