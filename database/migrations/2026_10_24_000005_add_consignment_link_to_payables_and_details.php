<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K2 Konsinyasi.
     *
     * payables.transaction_id: hutang konsinyasi tertaut ke penjualan yang
     * menciptakannya (satu hutang per penitip per transaksi, ditegakkan
     * unique index; NULL untuk hutang manual tetap boleh berulang).
     *
     * transaction_details snapshot: kondisi SAAT penjualan — penitip,
     * status titipan, dan harga beli — supaya pembalikan saat retur tetap
     * tepat meski produk sudah diubah/dipetakan ulang sesudahnya.
     * supplier_id di detail sengaja TANPA foreign key: ini riwayat, bukan
     * relasi hidup (penghapusan supplier tidak boleh menghapus jejaknya).
     */
    public function up(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->after('purchase_order_id')->constrained('transactions')->nullOnDelete();
            $table->unique(['transaction_id', 'supplier_id']);
        });

        Schema::table('transaction_details', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('product_id');
            $table->boolean('is_consignment')->default(false)->after('supplier_id');
            $table->integer('buy_price')->nullable()->after('is_consignment');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropColumn(['supplier_id', 'is_consignment', 'buy_price']);
        });

        Schema::table('payables', function (Blueprint $table) {
            $table->dropUnique(['transaction_id', 'supplier_id']);
            $table->dropForeign(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};
