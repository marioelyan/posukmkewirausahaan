<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chart of accounts (spec §2.1, fase 1 akuntansi inti).
 *
 * One code structure applies to every business; each business gets its own
 * rows (plus a null-business scope for legacy/global data). Account
 * resolution is always explicit by (business_id, code) in JournalService —
 * this model deliberately does NOT use BelongsToBusiness: that trait fails
 * closed (empty result) when multi-business is enabled without an active
 * business context, which would break journal reads from background jobs,
 * reports, and the backfill command.
 *
 * 3200 Laba Tahun Berjalan is intentionally ABSENT from CHART: it is a
 * computed balance-sheet line, not a posted account.
 */
class Account extends Model
{
    public const CATEGORY_ASSET = 'aset';

    public const CATEGORY_LIABILITY = 'kewajiban';

    public const CATEGORY_EQUITY = 'modal';

    public const CATEGORY_REVENUE = 'pendapatan';

    public const CATEGORY_EXPENSE = 'beban';

    /**
     * Canonical chart: code => [name, category].
     * Single source of truth for AccountSeeder and JournalService.
     */
    public const CHART = [
        '1100' => ['Kas', self::CATEGORY_ASSET],
        '1110' => ['Bank', self::CATEGORY_ASSET],
        '1120' => ['Piutang Usaha', self::CATEGORY_ASSET],
        '1130' => ['Piutang Lainnya', self::CATEGORY_ASSET],
        '1140' => ['Persediaan Barang Dagang', self::CATEGORY_ASSET],
        '2100' => ['Hutang Usaha', self::CATEGORY_LIABILITY],
        '2110' => ['Hutang Penitip', self::CATEGORY_LIABILITY],
        '2120' => ['Hutang Pajak', self::CATEGORY_LIABILITY],
        // Reserved (Q1 decided talangan_masuk = repayment, so no journal
        // uses 2130 yet); seeded so the code exists when needed.
        '2130' => ['Hutang Lainnya', self::CATEGORY_LIABILITY],
        '3100' => ['Modal Pemilik', self::CATEGORY_EQUITY],
        // Added as a consequence of Q5 (setoran-out destination field).
        '3110' => ['Prive', self::CATEGORY_EQUITY],
        '4100' => ['Pendapatan Penjualan', self::CATEGORY_REVENUE],
        '4110' => ['Retur Penjualan', self::CATEGORY_REVENUE],
        '4200' => ['Pendapatan Lainnya', self::CATEGORY_REVENUE],
        '5100' => ['HPP', self::CATEGORY_EXPENSE],
        '5200' => ['Beban Perlengkapan', self::CATEGORY_EXPENSE],
        '5210' => ['Beban Sewa Tempat', self::CATEGORY_EXPENSE],
        '5220' => ['Beban Gaji & Upah', self::CATEGORY_EXPENSE],
        '5230' => ['Beban Listrik & Air', self::CATEGORY_EXPENSE],
        '5240' => ['Beban Transport & Pengiriman', self::CATEGORY_EXPENSE],
        '5250' => ['Beban Pemasaran', self::CATEGORY_EXPENSE],
        '5260' => ['Beban Peralatan Kecil', self::CATEGORY_EXPENSE],
        '5270' => ['Beban Lainnya', self::CATEGORY_EXPENSE],
        '5280' => ['Beban Selisih Kas', self::CATEGORY_EXPENSE],
        '5300' => ['Beban Pajak', self::CATEGORY_EXPENSE],
    ];

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'category',
        'is_active',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
