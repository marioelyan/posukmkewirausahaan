<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->bigInteger('base_unit_price')->default(0)->after('qty');
            $table->bigInteger('unit_price')->default(0)->after('base_unit_price');
            $table->bigInteger('discount_total')->default(0)->after('price');
            $table->foreignId('pricing_rule_id')->nullable()->after('discount_total')->constrained('pricing_rules')->nullOnDelete();
            $table->string('pricing_rule_name')->nullable()->after('pricing_rule_id');
        });

        DB::table('transaction_details')
            ->select(['id', 'qty', 'price'])
            ->orderBy('id')
            ->chunkById(100, function ($details) {
                foreach ($details as $detail) {
                    $qty = max(1, (int) $detail->qty);
                    $unitPrice = (int) round(((int) $detail->price) / $qty);

                    DB::table('transaction_details')
                        ->where('id', $detail->id)
                        ->update([
                            'base_unit_price' => $unitPrice,
                            'unit_price' => $unitPrice,
                            'discount_total' => 0,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropForeign(['pricing_rule_id']);
            $table->dropColumn([
                'base_unit_price',
                'unit_price',
                'discount_total',
                'pricing_rule_id',
                'pricing_rule_name',
            ]);
        });
    }
};
