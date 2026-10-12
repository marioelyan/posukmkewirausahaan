<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SETTINGS-01 (Q5 / ADR-04 option C): give the settings table a business
 * dimension so tenant-owned configuration can be stored per business while
 * platform keys keep using the single global row.
 *
 * Resolution becomes `outlet -> business -> global` (ADR-04 §7). The unique
 * scope moves from `(key, outlet_id)` to `(key, business_id, outlet_id)`.
 *
 * Additive and backward compatible: with `multi_business.enabled = false` all
 * writes keep `business_id = null`, so legacy single-tenant behaviour is
 * byte-for-byte identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'business_id')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('outlet_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_key_outlet_id_unique');
            $table->unique(['key', 'business_id', 'outlet_id'], 'settings_key_business_outlet_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'business_id')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_key_business_outlet_unique');
            $table->unique(['key', 'outlet_id']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
