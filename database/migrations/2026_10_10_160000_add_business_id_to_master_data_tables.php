<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned master data plus warehouse denormalisation.
     *
     * All columns are nullable and additive so existing installs keep working
     * before the backfill runs. Do NOT make these NOT NULL until every legacy
     * row has been mapped and verified (see `business:backfill`).
     *
     * @var array<int, string>
     */
    private array $tables = [
        'products',
        'categories',
        'customers',
        'suppliers',
        'units',
        'price_lists',
        'warehouses',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('business_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('businesses')
                    ->nullOnDelete();

                $blueprint->index('business_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['business_id']);
                $blueprint->dropIndex(['business_id']);
                $blueprint->dropColumn('business_id');
            });
        }
    }
};
