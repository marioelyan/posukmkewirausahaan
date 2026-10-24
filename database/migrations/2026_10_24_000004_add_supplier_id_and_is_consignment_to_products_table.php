<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K1 Konsinyasi: link produk ke supplier (penitip) + penanda konsinyasi.
     *
     * supplier_id nullable: produk biasa tetap tanpa supplier. nullOnDelete:
     * menghapus supplier tidak menghapus produknya, hanya melepas kaitannya.
     * is_consignment membedakan produk titipan dari produk yang sekadar
     * punya supplier (misal dibeli putus). Hutang konsinyasi (K2) hanya
     * terbentuk bila is_consignment true DAN supplier_id terisi.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('category_id')->constrained('suppliers')->nullOnDelete();
            $table->boolean('is_consignment')->default(false)->after('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['supplier_id', 'is_consignment']);
        });
    }
};
